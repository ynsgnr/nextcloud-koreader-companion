<?php
namespace OCA\KoreaderCompanion\Listener;

use OCA\KoreaderCompanion\BackgroundJob\ExtractMetadataJob;
use OCA\KoreaderCompanion\Service\BookService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Config\IUserConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Listens for file deletion events to clean up related database records
 * when ebook files are deleted from the filesystem
 */
/**
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class FileDeleteListener implements IEventListener {

    private $config;
    private $db;
    private LoggerInterface $logger;

    public function __construct(
        IUserConfig $config,
        IDBConnection $db,
        LoggerInterface $logger,
        private IJobList $jobList,
        private IRootFolder $rootFolder
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        $node = $event->getNode();

        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            return;
        }

        if (!$this->isEbookInBooksFolder($node)) {
            return;
        }

        $userId = $this->extractUserIdFromPath($node->getPath());
        if (!$userId) {
            return;
        }

        $fileId = $node->getId();
        $this->cleanupFileReferences($fileId, $userId, $node->getPath());
        $this->deleteOptimizedCopy($node, $userId);

        // Drop the extraction job too, if one is still queued. It would run,
        // find the file gone and no-op -- harmless, but it leaves rows in oc_jobs
        // for work that can never happen, and on a busy instance those accumulate.
        $this->jobList->remove(ExtractMetadataJob::class, [
            'fileId' => $fileId,
            'userId' => $userId,
        ]);
    }

    private function isEbookInBooksFolder($node): bool {
        $path = $node->getPath();

        // Extract user ID from path to get their configured folder
        $userId = $this->extractUserIdFromPath($path);
        if (!$userId) {
            return false;
        }

        $folderName = $this->config->getValueString($userId, 'koreader_companion', 'folder', 'eBooks');

        // Check if file is in the configured eBooks folder
        if (strpos($path, "/files/$folderName/") === false) {
            return false;
        }

        // Deletions inside the mirror aren't library deletions -- the mirror
        // copy is cleaned up below, from the source file's own delete event.
        if (strpos($path, "/files/$folderName/" . BookService::OPTIMIZED_FOLDER_NAME . "/") !== false) {
            return false;
        }

        // Check if it's an ebook file
        $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        return in_array($extension, BookService::SUPPORTED_EXTENSIONS, true);
    }

    /** Best-effort: remove the opds-optimized mirror copy for a deleted source file. */
    private function deleteOptimizedCopy(Node $node, string $userId): void {
        try {
            $folderName = $this->config->getValueString($userId, 'koreader_companion', 'folder', 'eBooks');
            $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));

            $userFolder = $this->rootFolder->getUserFolder($userId);
            $booksFolder = $userFolder->get($folderName);
            $optimizedFolder = $booksFolder->get(BookService::OPTIMIZED_FOLDER_NAME);
            $key = BookService::OPTIMIZED_NODE_KEY_PREFIX . $node->getId();
            $nodeId = (int)$this->config->getValueString($userId, 'koreader_companion', $key, '0');
            $copies = $nodeId > 0 ? $userFolder->getById($nodeId) : [];
            if (!empty($copies)) {
                $copies[0]->delete();
            } else {
                // Copy made before mirror files were named after the book.
                $optimizedFolder->get($node->getId() . '.' . $extension)->delete();
            }
            $this->config->deleteUserConfig($userId, 'koreader_companion', $key);
        } catch (\Exception $e) {
            // Nothing to clean up, or the mirror was never built -- fine either way.
        }
    }

    private function extractUserIdFromPath(string $path): ?string {
        if (preg_match('/^\/([^\/]+)\/files\//', $path, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function cleanupFileReferences(int $fileId, string $userId, string $filePath): void {
        try {
            // Begin transaction to ensure atomic cleanup
            $this->db->beginTransaction();

            // Get metadata ID for this file
            $metadataId = $this->getMetadataId($userId, $fileId);
            
            if ($metadataId) {
                // Get all document hashes for this book from hash mappings
                $documentHashes = $this->getDocumentHashesForBook($metadataId, $userId);
                
                if (!empty($documentHashes)) {
                    // Remove sync progress for all hashes of this book
                    $progressRemoved = $this->removeSyncProgressForHashes($documentHashes, $userId);
                    if ($progressRemoved > 0) {
                        $this->logger->info('File deletion cleanup - removed sync progress records', [
                            'records_removed' => $progressRemoved,
                            'file_path' => $filePath
                        ]);
                    }
                }

                // Remove all hash mappings for this book
                $mappingsRemoved = $this->removeHashMappings($metadataId, $userId);
                if ($mappingsRemoved > 0) {
                    $this->logger->info('File deletion cleanup - removed hash mappings', [
                        'mappings_removed' => $mappingsRemoved,
                        'file_path' => $filePath
                    ]);
                }

                // Remove metadata record
                $metadataRemoved = $this->removeMetadata($userId, $fileId);
                if ($metadataRemoved > 0) {
                    $this->logger->info('File deletion cleanup - removed metadata', [
                        'file_path' => $filePath
                    ]);
                }
            }

            $this->db->commit();

            $this->logger->info('Successfully cleaned up all database references for deleted file', [
                'file_path' => $filePath,
                'user' => $userId
            ]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to cleanup references for deleted file', [
                'file_path' => $filePath,
                'exception' => $e
            ]);
        }
    }

    private function getMetadataId(string $userId, int $fileId): ?int {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $metadataId = $result->fetchOne();
            $result->closeCursor();

            return $metadataId ? (int)$metadataId : null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve metadata ID in file deletion cleanup', [
                'exception' => $e
            ]);
            return null;
        }
    }

    private function getDocumentHashesForBook(int $metadataId, string $userId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('document_hash')
                ->from('koreader_hash_mapping')
                ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            $hashes = [];
            while ($row = $result->fetch()) {
                $hashes[] = $row['document_hash'];
            }
            $result->closeCursor();

            return $hashes;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get document hashes in file deletion cleanup', [
                'exception' => $e
            ]);
            return [];
        }
    }

    private function removeSyncProgressForHashes(array $documentHashes, string $userId): int {
        if (empty($documentHashes)) {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_sync_progress')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->in('document_hash', $qb->createNamedParameter($documentHashes, \OCP\DB\IQueryBuilder::PARAM_STR_ARRAY)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove sync progress in file deletion cleanup', [
                'exception' => $e
            ]);
            return 0;
        }
    }

    private function removeHashMappings(int $metadataId, string $userId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_hash_mapping')
               ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove hash mappings in file deletion cleanup', [
                'exception' => $e
            ]);
            return 0;
        }
    }

    private function removeMetadata(string $userId, int $fileId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove metadata in file deletion cleanup', [
                'exception' => $e
            ]);
            return 0;
        }
    }
}