<?php
namespace OCA\KoreaderCompanion\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Config\IUserConfig;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\DataResponse;
use Psr\Log\LoggerInterface;

class BookService {

    /**
     * Extensions the library indexes.
     *
     * cbz was missing even though it is the more common comic container and the
     * only one PHP can read unaided -- CBR is RAR and needs ext-rar or an unrar
     * binary. Keep this list as the single source of truth; it used to be
     * duplicated in four places, and the listeners had their own copies.
     */
    public const SUPPORTED_EXTENSIONS = ['epub', 'pdf', 'cbr', 'cbz'];

    /** Comic archives, handled by the same metadata path. */
    public const COMIC_EXTENSIONS = ['cbr', 'cbz'];

    /**
     * OPDS download mirror, nested inside the library folder.
     *
     * Lives inside the same folder the indexer and file-event listeners watch,
     * so every folder walk and path match must skip this name -- otherwise a
     * mirrored copy gets indexed (and re-optimized) as if it were a new book.
     */
    public const OPTIMIZED_FOLDER_NAME = 'opds-optimized';

    /**
     * Per-user config key prefix remembering which mirror node belongs to a source
     * file id. Mirror files are named "Author - Title (opt).ext" (so they are
     * recognisable on a device), which means the name can't be used for lookup.
     */
    public const OPTIMIZED_NODE_KEY_PREFIX = 'opds_opt_node_';

    private const DEFAULT_OPTIMIZE_MAX_WIDTH = 1600;
    private const DEFAULT_OPTIMIZE_MAX_HEIGHT = 2400;
    private const DEFAULT_OPTIMIZE_QUALITY = 85;

    private $rootFolder;
    private $config;
    private $userSession;
    private $db;
    private $pdfExtractor;
    private DocumentHashGenerator $hashGenerator;
    private IPreview $previewManager;
    private EpubOptimizerService $epubOptimizer;
    private LoggerInterface $logger;

    public function __construct(
        IRootFolder $rootFolder,
        IUserConfig $config,
        IUserSession $userSession,
        IDBConnection $db,
        PdfMetadataExtractor $pdfExtractor,
        DocumentHashGenerator $hashGenerator,
        IPreview $previewManager,
        EpubOptimizerService $epubOptimizer,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->userSession = $userSession;
        $this->db = $db;
        $this->pdfExtractor = $pdfExtractor;
        $this->hashGenerator = $hashGenerator;
        $this->previewManager = $previewManager;
        $this->epubOptimizer = $epubOptimizer;
        $this->logger = $logger;
    }


    /**
     * The user this call is for.
     *
     * These entry points are reached from session-authenticated requests only.
     * The KOReader sync API, which has no session, no longer calls into them at
     * all -- it works from file ids and passes the user explicitly -- which is
     * what made it possible to drop IUserSession::setUser().
     */
    private function currentUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    /**
     * Get paginated books from database with optional sorting
     */
    public function getBooks($page = null, $perPage = null, $sort = 'title', $skipMetadataUpdate = false) {
        // If pagination parameters are provided, use database-based pagination
        if ($page !== null && $perPage !== null) {
            return $this->getPaginatedBooks($page, $perPage, $sort, $skipMetadataUpdate);
        }
        
        // Otherwise, maintain backward compatibility with file-system scanning
        $userId = $this->currentUserId();
        if ($userId === null) {
            return [];
        }

        $folderName = $this->config->getValueString($userId, 'koreader_companion', 'folder', 'eBooks');
        $userFolder = $this->rootFolder->getUserFolder($userId);
        
        try {
            $booksFolder = $userFolder->get($folderName);
        } catch (\Exception $e) {
            // Folder doesn't exist, return empty array
            return [];
        }

        if (!$booksFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
            return [];
        }

        $books = [];
        $this->scanFolder($booksFolder, $books);
        
        // Sort books by title (case-insensitive)
        usort($books, function($a, $b) {
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });
        
        return $books;
    }

    /**
     * Get paginated books from database and file system
     */
    private function getPaginatedBooks($page = 1, $perPage = 20, $sort = 'title', $skipMetadataUpdate = false) {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return [];
        }

        $offset = ($page - 1) * $perPage;

        // First, ensure metadata is up to date by scanning for new files (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }
        
        // Now query database for paginated results
        try {
            $qb = $this->db->getQueryBuilder();
            // Aliased 'm' because the "last updated" sort correlates a subquery
            // against this table.
            $qb->select('*')
               ->from('koreader_metadata', 'm')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);

            $this->applySort($qb, $sort);

            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get paginated books', [
                'exception' => $e
            ]);
            // Fallback to filesystem scanning
            return $this->getBooks();
        }
    }

    /**
     * Apply a sort order, from an allow-list.
     *
     * Shared so the list and the search cannot drift apart -- they had, with
     * search hardcoding title. The switch is also what keeps a request parameter
     * out of an ORDER BY clause.
     */
    private function applySort(IQueryBuilder $qb, string $sort): void {
        switch ($sort) {
            case 'recent':
                $qb->orderBy('created_at', 'DESC');
                break;
            case 'updated':
                // "Last updated" means the book *or* its reading progress, so this
                // reaches through the hash mappings into the sync table and takes
                // whichever is newer. A correlated subquery rather than a join, so
                // it cannot multiply rows when a book has several document hashes
                // (binary and filename) or several devices reporting.
                //
                // Identifiers are deliberately unquoted: createFunction() passes
                // raw SQL straight through without translating quoting, and
                // backticks would break PostgreSQL.
                $qb->orderBy($qb->createFunction(
                    'COALESCE('
                    . '(SELECT MAX(p.updated_at) FROM *PREFIX*koreader_sync_progress p'
                    . ' INNER JOIN *PREFIX*koreader_hash_mapping h'
                    . ' ON h.document_hash = p.document_hash AND h.user_id = p.user_id'
                    . ' WHERE h.metadata_id = m.id),'
                    . ' m.updated_at)'
                ), 'DESC');
                break;
            case 'author':
                $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                break;
            case 'publication_date':
                $qb->orderBy('publication_date', 'DESC');
                break;
            case 'title':
            default:
                $qb->orderBy('title', 'ASC');
                break;
        }
    }

    /**
     * Get total count of books for pagination
     */
    public function getTotalBookCount($skipMetadataUpdate = false) {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return 0;
        }


        // Ensure metadata is up to date first (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'total_count'))
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

            $result = $qb->executeQuery();
                
            $count = (int)$result->fetchOne();
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get total book count', [
                'exception' => $e
            ]);
            // Fallback to counting filesystem results
            return count($this->getBooks());
        }
    }


    /**
     * Ensure metadata database is up to date by scanning filesystem
     * Optimized to load all metadata in one query instead of per-file queries
     */
    /**
     * How often the filesystem reconciliation walk may run, per user.
     *
     * The database is kept current in real time by FileCreationListener and
     * ExtractMetadataJob. This walk is the safety net for files that arrived
     * without an event -- `occ files:scan`, a restore from backup, an external
     * storage mount -- so it does not have to happen on every request.
     *
     * It used to. Every OPDS feed, facet feed and library listing triggered a
     * full recursive directory walk of the library, which is the bulk of the
     * amplification an anonymous caller with valid credentials could provoke.
     * Five minutes matches Nextcloud's own stock cron cadence.
     */
    private const RECONCILE_INTERVAL_SECONDS = 300;

    public function ensureMetadataUpToDate($userId, bool $force = false) {
        if (!$force && !$this->reconcileIsDue($userId)) {
            return;
        }

        try {
            $folderName = $this->config->getValueString($userId, 'koreader_companion', 'folder', 'eBooks');
            $userFolder = $this->rootFolder->getUserFolder($userId);

            try {
                $booksFolder = $userFolder->get($folderName);
            } catch (\Exception $e) {
                return;
            }

            if (!$booksFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                return;
            }

            $existingMetadata = $this->loadExistingMetadata($userId);

            $this->syncFolderToDatabase($booksFolder, $userId, $existingMetadata);

            $this->cleanupOrphanedMetadata($userId);

            $this->markReconciled($userId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update metadata', [
                'exception' => $e
            ]);
        }
    }

    private function reconcileIsDue(string $userId): bool {
        $last = (int)$this->config->getValueString($userId, 'koreader_companion', 'last_reconcile', '0');

        return (time() - $last) >= self::RECONCILE_INTERVAL_SECONDS;
    }

    private function markReconciled(string $userId): void {
        $this->config->setValueString($userId, 'koreader_companion', 'last_reconcile', (string)time());
    }

    private function loadExistingMetadata(string $userId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id', 'file_id', 'updated_at')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            $metadata = [];
            while ($row = $result->fetch()) {
                $metadata[$row['file_id']] = [
                    'id' => $row['id'],
                    'updated_at' => $row['updated_at']
                ];
            }
            $result->closeCursor();

            return $metadata;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load existing metadata', [
                'exception' => $e
            ]);
            return [];
        }
    }

    /**
     * States for the indexing_state column.
     */
    public const STATE_PENDING = 'pending';
    public const STATE_DONE = 'done';

    /**
     * Record a book as present without reading its contents.
     *
     * Called from the upload event listener, which runs inside the transaction
     * Nextcloud opened for the write. Everything used here is already on the
     * Node in memory -- id, path, name, mtime -- so nothing re-reads
     * oc_filecache. That matters: reading a table the same transaction has
     * written is what Nextcloud's "dirty table reads" assertion rejects, and it
     * is why extraction used to fail silently whenever debug was enabled.
     *
     * The real metadata arrives later, from ExtractMetadataJob.
     */
    public function markFilePending(Node $file, string $userId): void {
        $fileId = $file->getId();
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        $now = date('Y-m-d H:i:s');

        $qb = $this->db->getQueryBuilder();
        $existing = $qb->select('id')
            ->from('koreader_metadata')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeQuery();
        $row = $existing->fetch();
        $existing->closeCursor();

        if ($row) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('koreader_metadata')
                ->set('indexing_state', $qb->createNamedParameter(self::STATE_PENDING))
                ->set('file_path', $qb->createNamedParameter($file->getPath()))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))
                ->executeStatement();
            return;
        }

        // Title falls back to the filename so the row is never blank; the job
        // overwrites it with the real title.
        $qb = $this->db->getQueryBuilder();
        $qb->insert('koreader_metadata')
            ->values([
                'user_id' => $qb->createNamedParameter($userId),
                'file_id' => $qb->createNamedParameter($fileId),
                'file_path' => $qb->createNamedParameter($file->getPath()),
                'title' => $qb->createNamedParameter(pathinfo($file->getName(), PATHINFO_FILENAME)),
                'author' => $qb->createNamedParameter(''),
                'file_format' => $qb->createNamedParameter($extension),
                'indexing_state' => $qb->createNamedParameter(self::STATE_PENDING),
                'created_at' => $qb->createNamedParameter($now),
                'updated_at' => $qb->createNamedParameter($now),
            ])
            ->executeStatement();
    }

    /**
     * How many pending books one "extract now" request will work through.
     *
     * Bounded on purpose: the work is proportional to file size and the request
     * is user-triggered, so an unbounded loop over a large library would hold a
     * PHP worker for minutes. The UI reports what is left and can be clicked
     * again.
     */
    public const PENDING_BATCH_LIMIT = 25;

    /**
     * Extract metadata for one file and mark it done. Runs from the background
     * job, outside any upload transaction, so reading the file is safe here.
     *
     * @param bool $force Re-extract even if the row is already marked done.
     */
    public function indexFile(Node $file, string $userId, bool $force = false): void {
        $fileId = $file->getId();

        // Deliberately not ensureFileInDatabase(): that skips a row whose
        // updated_at is newer than the file's mtime, and markFilePending() has
        // just stamped updated_at to now -- so it would decide there was nothing
        // to do and the book would sit there titled after its filename forever.
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id', 'indexing_state')
            ->from('koreader_metadata')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        // Never overwrite a row that already holds real metadata. The web upload
        // form writes the user's own title/author synchronously and marks the row
        // done, but the file listener has already queued this job for the same
        // file id -- so without this guard cron would silently replace whatever
        // the user typed with the file's embedded metadata minutes later.
        //
        // 'pending' is the signal that extraction is still owed, and
        // markFilePending() sets it again whenever the file itself changes, so a
        // genuine re-index is unaffected.
        if ($row && !$force && ($row['indexing_state'] ?? self::STATE_PENDING) === self::STATE_DONE) {
            return;
        }

        if ($row) {
            $this->updateFileMetadata($file, $userId, $row['id']);
        } else {
            $this->insertFileMetadata($file, $userId);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('koreader_metadata')
            ->set('indexing_state', $qb->createNamedParameter(self::STATE_DONE))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeStatement();
    }

    public function countPendingBooks(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*', 'pending'))
            ->from('koreader_metadata')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('indexing_state', $qb->createNamedParameter(self::STATE_PENDING)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Extract metadata for books still waiting on it, now, in this request.
     *
     * Nextcloud has no way to run a specific background job on demand -- web
     * cron.php runs whatever job is next globally, and does nothing at all when
     * the instance uses system cron -- so "extract now" has to do the work
     * itself rather than poke the queue. That is safe here: unlike the upload
     * listener, this runs in its own request with no open write transaction.
     *
     * The queued ExtractMetadataJob stays as it is. Whichever runs first marks
     * the row done and the other one skips it.
     *
     * @return array{processed: int, failed: int, remaining: int}
     */
    public function processPendingBooks(string $userId, int $limit = self::PENDING_BATCH_LIMIT): array {
        // Counted up front, before anything is written. Counting afterwards reads
        // a table this request has just written, which is exactly what
        // Nextcloud's "dirty table reads" assertion rejects -- the same trap that
        // forced extraction out of the upload listener in the first place. It
        // throws with debug enabled and is a silent landmine without it.
        $pendingBefore = $this->countPendingBooks($userId);

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('file_id')
            ->from('koreader_metadata')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('indexing_state', $qb->createNamedParameter(self::STATE_PENDING)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery();
        $fileIds = $result->fetchAll(\PDO::FETCH_COLUMN);
        $result->closeCursor();

        $processed = 0;
        $failed = 0;

        if ($fileIds !== []) {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            foreach ($fileIds as $fileId) {
                try {
                    $nodes = $userFolder->getById((int)$fileId);
                    if ($nodes === []) {
                        // Gone since it was queued; the delete listener owns the row.
                        continue;
                    }
                    $this->indexFile($nodes[0], $userId);
                    $processed++;
                } catch (\Throwable $e) {
                    // One unreadable book must not abort the whole batch.
                    $failed++;
                    $this->logger->warning('On-demand metadata extraction failed for one file', [
                        'app' => 'koreader_companion',
                        'fileId' => $fileId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        // Derived, not re-queried, for the reason above. Approximate if something
        // else added books mid-run, which only affects a progress message.
        return [
            'processed' => $processed,
            'failed' => $failed,
            'remaining' => max(0, $pendingBefore - $processed),
        ];
    }

    public function syncFileMetadata(Node $file, string $userId): void {
        $this->ensureFileInDatabase($file, $userId);
    }

    private function syncFolderToDatabase(Node $folder, string $userId, array &$existingMetadata) {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                if (strtolower($node->getName()) === self::OPTIMIZED_FOLDER_NAME) {
                    continue;
                }
                $this->syncFolderToDatabase($node, $userId, $existingMetadata);
            } else {
                $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
                if (in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                    $this->ensureFileInDatabase($node, $userId, $existingMetadata);
                }
            }
        }
    }

    private function ensureFileInDatabase(Node $file, string $userId, ?array &$existingMetadata = null) {
        try {
            $fileId = $file->getId();
            $fileModTime = $file->getMTime();

            if ($existingMetadata !== null && isset($existingMetadata[$fileId])) {
                $metadata = $existingMetadata[$fileId];
                $lastUpdated = new \DateTime($metadata['updated_at']);
                if ($fileModTime <= $lastUpdated->getTimestamp()) {
                    $this->backfillOptimizedCopy($file, $userId, (int)$metadata['id']);
                    return;
                }
                $this->updateFileMetadata($file, $userId, $metadata['id']);
            } elseif ($existingMetadata !== null) {
                $this->insertFileMetadata($file, $userId);
            } else {
                $qb = $this->db->getQueryBuilder();
                $result = $qb->select('id', 'updated_at')
                    ->from('koreader_metadata')
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                    ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                    ->executeQuery();

                $existingRow = $result->fetch();
                $result->closeCursor();

                if ($existingRow) {
                    $lastUpdated = new \DateTime($existingRow['updated_at']);
                    if ($fileModTime <= $lastUpdated->getTimestamp()) {
                        $this->backfillOptimizedCopy($file, $userId, (int)$existingRow['id']);
                        return;
                    }
                    $this->updateFileMetadata($file, $userId, $existingRow['id']);
                } else {
                    $this->insertFileMetadata($file, $userId);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to ensure file in database', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
        }
    }

    /**
     * Self-healing hook for files whose metadata is already current -- runs on
     * every reconciliation pass, but ensureOptimizedCopy() is a cheap existence
     * check unless the mirror is actually missing (first run, or after a
     * settings change wiped it).
     */
    private function backfillOptimizedCopy(Node $file, string $userId, int $metadataId): void {
        $format = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        $optimized = $this->ensureOptimizedCopy($file, $userId, $format, false);
        if ($optimized['created']) {
            $this->createHashMappingsForFile($file, $userId, $metadataId, $optimized['node']);
        }
    }

    /**
     * Builds the mirror and hash mappings for a file whose metadata row was just
     * written directly by PageController::storeBookMetadata() (the web upload
     * form) rather than through insertFileMetadata()/updateFileMetadata(). That
     * path marks the row done immediately, which makes indexFile() skip it on
     * the next cron run -- so nothing else would ever call ensureOptimizedCopy()
     * or createHashMappingsForFile() for it. force=true because this is either a
     * brand new file or one whose content just changed.
     */
    public function finalizeUploadedFile(Node $file, string $userId, int $metadataId): void {
        $format = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        $optimized = $this->ensureOptimizedCopy($file, $userId, $format, true);
        $this->createHashMappingsForFile($file, $userId, $metadataId, $optimized['node']);
    }

    /**
     * Insert new file metadata into database
     */
    private function insertFileMetadata(Node $file, $userId) {
        try {
            $metadata = $this->extractMetadata($file);

            $qb = $this->db->getQueryBuilder();
            $qb->insert('koreader_metadata')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'file_id' => $qb->createNamedParameter($file->getId()),
                    'file_path' => $qb->createNamedParameter($file->getPath()),
                    'title' => $qb->createNamedParameter($metadata['title']),
                    'author' => $qb->createNamedParameter($metadata['author']),
                    'description' => $qb->createNamedParameter($metadata['description']),
                    'publisher' => $qb->createNamedParameter($metadata['publisher']),
                    'publication_date' => $qb->createNamedParameter($metadata['publication_date'] ?: null),
                    'language' => $qb->createNamedParameter($metadata['language']),
                    'series' => $qb->createNamedParameter($metadata['series']),
                    'series_index' => $qb->createNamedParameter($this->resolveSeriesIndex($metadata)),
                    'subject' => $qb->createNamedParameter($metadata['subject']),
                    'tags' => $qb->createNamedParameter($metadata['tags']),
                    'file_format' => $qb->createNamedParameter($metadata['format']),
                    'issue' => $qb->createNamedParameter($metadata['issue']),
                    'volume' => $qb->createNamedParameter($metadata['volume']),
                    'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                    'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s'))
                ])
                ->executeStatement();

            $metadataId = $this->db->lastInsertId('oc_koreader_metadata');

            // Build the mirror before hashing -- the binary hash must match what
            // OPDS actually serves, not the original file.
            $optimized = $this->ensureOptimizedCopy($file, $userId, $metadata['format']);
            $this->createHashMappingsForFile($file, $userId, $metadataId, $optimized['node']);

        } catch (\Exception $e) {
            $this->logger->error('Failed to insert file metadata', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
        }
    }

    /**
     * Update existing file metadata in database
     */
    private function updateFileMetadata(Node $file, $userId, $metadataId) {
        try {
            $metadata = $this->extractMetadata($file);

            $qb = $this->db->getQueryBuilder();
            $qb->update('koreader_metadata')
                ->set('file_path', $qb->createNamedParameter($file->getPath()))
                ->set('title', $qb->createNamedParameter($metadata['title']))
                ->set('author', $qb->createNamedParameter($metadata['author']))
                ->set('description', $qb->createNamedParameter($metadata['description']))
                ->set('publisher', $qb->createNamedParameter($metadata['publisher']))
                ->set('publication_date', $qb->createNamedParameter($metadata['publication_date'] ?: null))
                ->set('language', $qb->createNamedParameter($metadata['language']))
                ->set('series', $qb->createNamedParameter($metadata['series']))
                ->set('series_index', $qb->createNamedParameter($this->resolveSeriesIndex($metadata)))
                ->set('subject', $qb->createNamedParameter($metadata['subject']))
                ->set('tags', $qb->createNamedParameter($metadata['tags']))
                ->set('file_format', $qb->createNamedParameter($metadata['format']))
                ->set('issue', $qb->createNamedParameter($metadata['issue']))
                ->set('volume', $qb->createNamedParameter($metadata['volume']))
                ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($metadataId)))
                ->executeStatement();

            // Content changed, so force a rebuild -- an existence check alone
            // would keep serving the stale copy.
            $optimized = $this->ensureOptimizedCopy($file, $userId, $metadata['format'], true);
            $this->createHashMappingsForFile($file, $userId, $metadataId, $optimized['node']);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update file metadata', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
        }
    }

    /**
     * Convert database row to book array format
     */
    private function convertDatabaseRowToBookArray($row, $userId) {
        try {
            // Get the file from Nextcloud filesystem
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $files = $userFolder->getById($row['file_id']);
            
            if (empty($files)) {
                // File no longer exists, should be cleaned up
                return null;
            }
            
            $file = $files[0];
            
            // Build book array in the same format as extractMetadata
            $book = [
                'id' => $row['file_id'],
                'name' => $file->getName(),
                'path' => $file->getPath(),
                'size' => $file->getSize(),
                'modified_time' => $file->getMTime(),
                'format' => $row['file_format'] ?? strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)),
                'title' => $row['title'] ?? pathinfo($file->getName(), PATHINFO_FILENAME),
                'author' => $row['author'] ?? 'Unknown',
                'description' => $row['description'] ?? '',
                'language' => $row['language'] ?? '',
                'publisher' => $row['publisher'] ?? '',
                'subject' => $row['subject'] ?? '',
                'publication_date' => $row['publication_date'] ?? '',
                'identifier' => '', // Not stored in current schema
                'cover' => null, // Handled dynamically
                'indexing_state' => $row['indexing_state'] ?? self::STATE_DONE,
                'series' => $row['series'] ?? '',
                'issue' => $row['issue'] ?? '',
                'volume' => $row['volume'] ?? '',
                'tags' => $row['tags'] ?? ''
            ];
            
            // Add sync progress if available
            $this->addSyncProgressToMetadata($file, $book);

            return $book;

        } catch (\Exception $e) {
            $this->logger->error('Failed to convert database row to book array', [
                'exception' => $e
            ]);
            return null;
        }
    }

    protected function scanFolder(Node $folder, &$books) {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                if (strtolower($node->getName()) === self::OPTIMIZED_FOLDER_NAME) {
                    continue;
                }
                $this->scanFolder($node, $books);
            } else {
                $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
                if (in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                    $books[] = $this->extractMetadata($node);
                }
            }
        }
    }

    private function extractMetadata(Node $file) {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

        $metadata = [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'path' => $file->getPath(),
            'size' => $file->getSize(),
            'modified_time' => $file->getMTime(),
            'format' => $extension,
            'title' => pathinfo($file->getName(), PATHINFO_FILENAME),
            'author' => 'Unknown',
            'description' => '',
            'language' => '',
            'publisher' => '',
            'subject' => '',
            'publication_date' => '',
            'identifier' => '',
            'cover' => null,
            'indexing_state' => self::STATE_DONE,
            // Add comic book specific fields
            'series' => '',
            'issue' => '',
            'volume' => '',
            'tags' => ''
        ];

        // Check for stored metadata first (prioritize user-provided metadata from upload)
        $storedMetadata = $this->getStoredMetadata($file);
        if ($storedMetadata) {
            // Use stored metadata as primary source
            $fieldsToOverride = ['title', 'author', 'description', 'language', 'publisher', 'publication_date', 'subject', 'series', 'issue', 'volume', 'tags'];
            foreach ($fieldsToOverride as $field) {
                if (!empty($storedMetadata[$field])) {
                    $metadata[$field] = $storedMetadata[$field];
                }
            }
        } else {
            // Extract metadata from file using server-side parsers
            if ($extension === 'epub') {
                $this->extractEpubMetadata($file, $metadata);
            } elseif ($extension === 'pdf') {
                $this->extractPdfMetadata($file, $metadata);
            } elseif (in_array($extension, self::COMIC_EXTENSIONS, true)) {
                // No class_exists guard: the filename parsing below needs no
                // archive reader at all, and ComicInfo.xml is read with
                // ZipArchive for cbz. Only cbr needs the optional dependency,
                // and that path degrades on its own.
                $this->extractComicMetadata($file, $metadata, $extension);
            }
        }

        // Add KOReader sync progress information
        $this->addSyncProgressToMetadata($file, $metadata);

        return $metadata;
    }

    /**
     * Extract metadata for display and editing (used in upload workflow)
     * Returns raw extracted metadata without progress information
     */
    public function extractMetadataForUpload(Node $file): array {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

        $metadata = [
            'title' => pathinfo($file->getName(), PATHINFO_FILENAME),
            'author' => '',
            'description' => '',
            'language' => '',
            'publisher' => '',
            'publication_date' => '',
            'subject' => '',
            'series' => '',
            'issue' => '',
            'volume' => '',
            'tags' => '',
            'format' => $extension
        ];

        try {
            // Extract metadata from file based on format
            if ($extension === 'epub') {
                $this->extractEpubMetadata($file, $metadata);
            } elseif ($extension === 'pdf') {
                $this->extractPdfMetadata($file, $metadata);
            } elseif (in_array($extension, self::COMIC_EXTENSIONS, true)) {
                $this->extractComicMetadata($file, $metadata, $extension);
            }
        } catch (\Exception $e) {
            // If extraction fails, keep the filename-based defaults
            $this->logger->error('Metadata extraction failed', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
        }

        return $metadata;
    }

    /**
     * Add KOReader sync progress information to book metadata
     */
    private function addSyncProgressToMetadata(Node $file, &$metadata) {
        try {
            $user = $this->userSession->getUser();
            $userId = null;
            
            if ($user) {
                $userId = $user->getUID();
            } else {
                // For API calls, try to extract user from file path
                $filePath = $file->getPath();
                if (preg_match('/^\/([^\/]+)\/files\//', $filePath, $matches)) {
                    $userId = $matches[1];
                } else {
                    return;
                }
            }

            // Get all document hashes for this file from hash mappings
            $documentHashes = $this->getDocumentHashesForFile($file->getId(), $userId);
            
            if (empty($documentHashes)) {
                $metadata['progress'] = null;
                return;
            }

            // Find the most recent sync progress for any of the document hashes
            $progress = $this->getMostRecentSyncProgress($documentHashes, $userId);
            
            if ($progress) {
                $metadata['progress'] = [
                    'percentage' => floatval($progress['percentage'] ?? 0) * 100.0,
                    'device' => $progress['device'] ?? 'Unknown',
                    'device_id' => $progress['device_id'] ?? '',
                    'updated_at' => $progress['updated_at'] ?? '',
                    'progress_data' => $progress['progress'] ?? ''
                ];
            } else {
                $metadata['progress'] = null;
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to add sync progress for file', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
            $metadata['progress'] = null;
        }
    }

    /**
     * Get all document hashes associated with a file
     */
    private function getDocumentHashesForFile(int $fileId, string $userId): array {
        try {
            // First get the metadata ID for this file
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $metadataId = $result->fetchOne();
            $result->closeCursor();

            if (!$metadataId) {
                return [];
            }

            // Now get all document hashes for this metadata
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
            $this->logger->error('Failed to get document hashes for file', [
                'file_id' => $fileId,
                'exception' => $e
            ]);
            return [];
        }
    }

    /**
     * Get the most recent sync progress for a set of document hashes
     */
    private function getMostRecentSyncProgress(array $documentHashes, string $userId): ?array {
        if (empty($documentHashes)) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from('koreader_sync_progress')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('document_hash', $qb->createNamedParameter($documentHashes, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
                ->orderBy('updated_at', 'DESC')
                ->setMaxResults(1)
                ->executeQuery();

            $progress = $result->fetch();
            $result->closeCursor();

            return $progress ?: null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get sync progress for hashes', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Get stored custom metadata from database for a file
     */
    private function getStoredMetadata(Node $file): ?array {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                // For API calls, try to extract user from file path
                $filePath = $file->getPath();
                if (preg_match('/^\/([^\/]+)\/files\//', $filePath, $matches)) {
                    $userId = $matches[1];
                } else {
                    return null;
                }
            } else {
                $userId = $user->getUID();
            }

            // Exclude pending rows. A pending row is the placeholder the upload
            // listener writes before queueing extraction -- its title is just the
            // filename. Treating that as stored user metadata made extractMetadata
            // skip parsing the file entirely, so a queued book kept its filename
            // as its title forever.
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
                ->andWhere($qb->expr()->neq(
                    'indexing_state',
                    $qb->createNamedParameter(self::STATE_PENDING)
                ))
                ->executeQuery();

            $storedMetadata = $result->fetch();
            $result->closeCursor();

            return $storedMetadata ?: null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve stored metadata for file', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
            return null;
        }
    }

    /**
     * Value for the series_index column.
     *
     * This column was fed from $metadata['issue'] only, so a real series index
     * was discarded: comics happened to work because they set 'issue', but an
     * EPUB carrying calibre:series_index had it silently dropped. Prefer the
     * explicit index and keep the issue number as the comic fallback.
     */
    private function resolveSeriesIndex(array $metadata): ?float {
        if (isset($metadata['series_index']) && $metadata['series_index'] !== '' && $metadata['series_index'] !== null) {
            return (float)$metadata['series_index'];
        }
        if (!empty($metadata['issue']) && is_numeric($metadata['issue'])) {
            return (float)$metadata['issue'];
        }
        return null;
    }

    private function extractEpubMetadata(Node $file, &$metadata) {
        try {
            // Read the EPUB file content
            $content = $file->getContent();
            
            // Create temporary file to work with ZipArchive
            $tempFile = tempnam(sys_get_temp_dir(), 'epub_meta_');
            file_put_contents($tempFile, $content);
            
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === TRUE) {
                $epubMetadata = $this->parseEpubOPF($zip);
                $zip->close();
                
                // Copy across whatever the OPF yielded.
                //
                // This used to be a hand-maintained list of if(!empty()) blocks,
                // which had drifted from what parseEpubOPF() actually returns:
                // 'series' and 'series_index' were never copied, and the date
                // branch looked for a 'date' key that parseEpubOPF has never
                // produced -- it returns 'publication_date' already parsed. So
                // EPUB series and publication dates both silently vanished here,
                // which is why the Year column was always empty for EPUBs.
                // Iterating keeps the two ends from drifting again.
                if ($epubMetadata) {
                    foreach ($epubMetadata as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $metadata[$key] = $value;
                        }
                    }
                }
            }

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

        } catch (\Exception $e) {
            // If extraction fails, fall back to filename parsing
            $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
            
            // Try to extract author and title from filename patterns like "Author - Title"
            if (strpos($filename, ' - ') !== false) {
                $parts = explode(' - ', $filename, 2);
                $metadata['author'] = trim($parts[0]);
                $metadata['title'] = trim($parts[1]);
            }
        }
    }

    private function extractPdfMetadata(Node $file, &$metadata) {
        try {
            $pdfMetadata = $this->pdfExtractor->extractMetadata($file);
            
            // Merge PDF metadata into existing metadata array
            foreach ($pdfMetadata as $key => $value) {
                if (!empty($value) || $key === 'title') {
                    $metadata[$key] = $value;
                }
            }
        } catch (\Exception $e) {
            // If extraction fails, keep defaults
        }
    }

    private function extractComicMetadata(Node $file, &$metadata, string $extension = 'cbr') {
        try {
            $filename = pathinfo($file->getName(), PATHINFO_FILENAME);

            // Set basic metadata from filename
            $metadata['title'] = $filename;
            $metadata['format'] = $extension;
            
            // Try to parse comic book information from filename
            // Common patterns: "Series Name #001 (Year)", "Series Name 001", etc.
            if (preg_match('/(.*?)\s*#?(\d+).*?\((\d{4})\)/', $filename, $matches)) {
                $metadata['title'] = trim($matches[1]) . ' #' . $matches[2];
                $metadata['series'] = trim($matches[1]);
                $metadata['issue'] = $matches[2];
                $metadata['publication_date'] = $this->parsePublicationDate($matches[3]);
            } elseif (preg_match('/(.*?)\s*(\d+)/', $filename, $matches)) {
                $metadata['title'] = trim($matches[1]) . ' #' . $matches[2];
                $metadata['series'] = trim($matches[1]);
                $metadata['issue'] = $matches[2];
            }
            
            // ComicInfo.xml, when the archive carries one, overrides the guesses above.
            $this->extractComicInfoMetadata($file, $metadata);
            
        } catch (\Exception $e) {
            // If extraction fails, keep defaults
            $this->logger->error('Comic metadata extraction failed', ['exception' => $e]);
        }
    }

    /**
     * Read ComicInfo.xml out of a comic archive, if it has one.
     *
     * This never worked before: it called Archive::make(), which returns an
     * ArchiveZipCreate *writer*, then getName()/getContent() on the items --
     * neither method exists on ArchiveItem. CBZ is now read with ZipArchive
     * directly, which needs no dependency at all; CBR still needs
     * kiwilan/php-archive plus an unrar binary and degrades quietly without them.
     */
    private function extractComicInfoMetadata(Node $file, &$metadata) {
        $localPath = null;
        try {
            $localPath = tempnam(sys_get_temp_dir(), 'comicinfo_');
            if ($localPath === false) {
                return;
            }
            file_put_contents($localPath, $file->getContent());

            $xml = $this->readComicInfoFromZip($localPath)
                ?? $this->readComicInfoFromRar($localPath);

            if ($xml !== null && $xml !== '') {
                $this->parseComicInfoXml($xml, $metadata);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ComicInfo.xml extraction failed', [
                'file_path' => $file->getPath(),
                'exception' => $e,
            ]);
        } finally {
            if ($localPath !== null && file_exists($localPath)) {
                unlink($localPath);
            }
        }
    }

    /** Handles cbz, and mislabelled cbr files that are really zips. */
    private function readComicInfoFromZip(string $localPath): ?string {
        $zip = new \ZipArchive();
        if ($zip->open($localPath) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && strtolower(basename($name)) === 'comicinfo.xml') {
                    $content = $zip->getFromIndex($i);
                    return $content === false ? null : $content;
                }
            }
            return null;
        } finally {
            $zip->close();
        }
    }

    private function readComicInfoFromRar(string $localPath): ?string {
        if (!class_exists(\Kiwilan\Archive\Archive::class)) {
            return null;
        }

        $archive = \Kiwilan\Archive\Archive::read($localPath);
        foreach ($archive->getFiles() as $item) {
            $path = $item->getPath() ?? $item->getFilename() ?? '';
            if (strtolower(basename($path)) === 'comicinfo.xml') {
                $content = $archive->getContent($item);
                return ($content === null || $content === '') ? null : $content;
            }
        }
        return null;
    }
    
    private function parseComicInfoXml($xmlContent, &$metadata) {
        try {
            $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$xml) {
                return;
            }
            
            // Extract comic-specific metadata
            if (!empty($xml->Title)) {
                $metadata['title'] = (string)$xml->Title;
            }
            
            if (!empty($xml->Series)) {
                $metadata['series'] = (string)$xml->Series;
                // Combine series and issue number for title if available
                if (!empty($xml->Number)) {
                    $metadata['title'] = $metadata['series'] . ' #' . (string)$xml->Number;
                }
            }
            
            if (!empty($xml->Number)) {
                $metadata['issue'] = (string)$xml->Number;
            }
            
            if (!empty($xml->Writer)) {
                $metadata['author'] = (string)$xml->Writer;
            }
            
            if (!empty($xml->Summary)) {
                $metadata['description'] = (string)$xml->Summary;
            }
            
            if (!empty($xml->Publisher)) {
                $metadata['publisher'] = (string)$xml->Publisher;
            }
            
            if (!empty($xml->Year)) {
                $metadata['publication_date'] = $this->parsePublicationDate((string)$xml->Year);
            }
            
            if (!empty($xml->Genre)) {
                $metadata['subject'] = (string)$xml->Genre;
            }
            
            if (!empty($xml->LanguageISO)) {
                $metadata['language'] = (string)$xml->LanguageISO;
            }
            
            // Additional comic-specific fields
            if (!empty($xml->Volume)) {
                $metadata['volume'] = (string)$xml->Volume;
            }
            
            if (!empty($xml->Web)) {
                $metadata['web'] = (string)$xml->Web;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('ComicInfo.xml parsing failed', ['exception' => $e]);
        }
    }


    public function searchBooks($query, $page = null, $perPage = null, $skipMetadataUpdate = false, $sort = 'title') {
        // If pagination parameters are provided, use database-based search
        if ($page !== null && $perPage !== null) {
            return $this->getPaginatedSearchResults($query, $page, $perPage, $skipMetadataUpdate, $sort);
        }
        
        // Otherwise, maintain backward compatibility with in-memory search
        $allBooks = $this->getBooks();
        
        if (empty($query)) {
            return $allBooks;
        }
        
        $query = strtolower($query);
        $results = [];
        
        foreach ($allBooks as $book) {
            if (stripos($book['title'], $query) !== false || 
                stripos($book['author'], $query) !== false ||
                stripos($book['description'], $query) !== false) {
                $results[] = $book;
            }
        }
        
        return $results;
    }

    /**
     * Get paginated search results from database
     */
    private function getPaginatedSearchResults($query, $page = 1, $perPage = 20, $skipMetadataUpdate = false, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        if (empty($query)) {
            return $this->getPaginatedBooks($page, $perPage, $sort);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata', 'm')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

            // Add search conditions
            $qb->andWhere($qb->expr()->orX(
                   $qb->expr()->iLike('title', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('author', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('description', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('series', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('subject', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('tags', $qb->createNamedParameter('%' . $query . '%'))
               ));

            $this->applySort($qb, $sort);
            $qb->setFirstResult($offset)
               ->setMaxResults($perPage);
               
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get paginated search results', ['exception' => $e]);
            // Fallback to in-memory search
            return $this->searchBooks($query);
        }
    }

    /**
     * Get total count of search results for pagination
     */
    public function getSearchResultCount($query, $skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        if (empty($query)) {
            return $this->getTotalBookCount($skipMetadataUpdate);
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'total_count'))
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->iLike('title', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('author', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('description', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('series', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('subject', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('tags', $qb->createNamedParameter('%' . $query . '%'))
                ))
                ->executeQuery();
                
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            
            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get search result count', ['exception' => $e]);
            // Fallback to in-memory search count
            return count($this->searchBooks($query));
        }
    }


    /**
     * One book, by file id.
     *
     * Looked up in SQL. This used to call getBooks() and walk the result
     * comparing ids, which meant a full recursive scan and parse of the library
     * for every single download, cover and reader open -- work proportional to
     * the library size to answer a question about one row.
     *
     * Ownership still holds: the row is scoped to the session user, and
     * convertDatabaseRowToBookArray() resolves the file through that user's own
     * folder, so a foreign file id finds nothing.
     */
    public function getBookById($id) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }

        $userId = $user->getUID();
        $fileId = (int)$id;

        $book = $this->findBookRow($userId, $fileId);
        if ($book !== null) {
            return $book;
        }

        // Not indexed yet -- a file can exist before the listener or the
        // background job has caught up. Resolve that one file and index it,
        // rather than falling back to scanning everything.
        try {
            $nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
            if ($nodes === []) {
                return null;
            }

            $file = $nodes[0];
            $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
            if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                return null;
            }

            $this->indexFile($file, $userId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to index a book on demand', [
                'app' => 'koreader_companion',
                'fileId' => $fileId,
                'exception' => $e,
            ]);
            return null;
        }

        return $this->findBookRow($userId, $fileId);
    }

    private function findBookRow(string $userId, int $fileId): ?array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('koreader_metadata')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? $this->convertDatabaseRowToBookArray($row, $userId) : null;
    }

    public function downloadBook($book, $format) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Unauthorized'], 401);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($book['id']);
            
            if (empty($files)) {
                return new DataResponse(['error' => 'File not found'], 404);
            }
            
            $file = $files[0];
            
            $response = new StreamResponse($file->fopen('r'));
            $response->addHeader('Content-Type', $this->getMimeType($format));
            $response->addHeader('Content-Disposition', $this->contentDisposition((string)$book['name']));
            $response->addHeader('Content-Length', $file->getSize());

            return $response;
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'File not found'], 404);
        }
    }

    /**
     * Build a Content-Disposition value that survives an awkward filename.
     *
     * The name used to be interpolated straight into the quoted string, so a `"`
     * in a book title broke out of the value. RFC 6266 wants both forms: an
     * ASCII-only `filename` for old clients and a percent-encoded `filename*`
     * carrying the real, possibly non-ASCII name.
     */
    private function contentDisposition(string $name): string {
        // Strip quotes, backslashes and control bytes -- none of them are legal in
        // a quoted header value, and CR/LF are how header injection is attempted.
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name) ?? '';
        $ascii = str_replace(['"', '\\'], '', $ascii);
        if ($ascii === '') {
            $ascii = 'book';
        }

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $ascii,
            rawurlencode($name)
        );
    }

    /**
     * Cover image for a book, served through Nextcloud's preview system.
     *
     * The extraction itself lives in OCA\KoreaderCompanion\Preview\* providers,
     * so the result is cached in preview storage instead of re-extracted per
     * request, and the same image is reachable from the web UI over
     * /core/preview with ordinary session auth. This endpoint stays because OPDS
     * clients want a thumbnail link.
     *
     * PDF has no provider: PDF because Nextcloud 34.0.2 disabled all
     * ImageMagick-backed providers for security (nextcloud/server#62802). It yields 404, not 501, so
     * clients treat it as "no cover" rather than "server broken".
     */
    public function getThumbnail($book) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Unauthorized'], 401);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($book['id']);

            if (empty($files)) {
                return new DataResponse(['error' => 'File not found'], 404);
            }

            $file = $files[0];
            if (!$this->previewManager->isAvailable($file)) {
                return new DataResponse(['message' => 'No cover available for this format'], 404);
            }

            $preview = $this->previewManager->getPreview($file, 256, 384);
            $response = new StreamResponse($preview->read());
            $response->addHeader('Content-Type', $preview->getMimeType() ?: 'image/jpeg');
            $response->addHeader('Content-Length', (string)$preview->getSize());
            $response->addHeader('Cache-Control', 'private, max-age=86400');
            return $response;
        } catch (NotFoundException $e) {
            return new DataResponse(['message' => 'No cover available'], 404);
        } catch (\Throwable $e) {
            $this->logger->warning('Cover lookup failed', [
                'book' => $book['id'] ?? null,
                'exception' => $e,
            ]);
            return new DataResponse(['message' => 'No cover available'], 404);
        }
    }


    private function parseEpubOPF($zip) {
        try {
            // Find the OPF file location from container.xml
            $containerXml = $zip->getFromName('META-INF/container.xml');
            if (!$containerXml) {
                return null;
            }
            
            $container = simplexml_load_string($containerXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$container) {
                return null;
            }
            
            $opfPath = (string)$container->rootfiles->rootfile['full-path'];
            if (!$opfPath) {
                return null;
            }
            
            // Parse the OPF file
            $opfContent = $zip->getFromName($opfPath);
            if (!$opfContent) {
                return null;
            }
            
            $opf = simplexml_load_string($opfContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$opf) {
                return null;
            }
            
            // Register namespaces
            $opf->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $opf->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');
            
            $metadata = [];
            
            // Extract title
            $titles = $opf->xpath('//dc:title');
            if (!empty($titles)) {
                $metadata['title'] = (string)$titles[0];
            }
            
            // Extract author(s)
            $authors = $opf->xpath('//dc:creator[@opf:role="aut"] | //dc:creator[not(@opf:role)] | //dc:creator');
            if (!empty($authors)) {
                $authorList = [];
                foreach ($authors as $author) {
                    $authorList[] = (string)$author;
                }
                $metadata['author'] = implode(', ', $authorList);
            }
            
            // Extract description
            $descriptions = $opf->xpath('//dc:description');
            if (!empty($descriptions)) {
                $metadata['description'] = (string)$descriptions[0];
            }
            
            // Extract language
            $languages = $opf->xpath('//dc:language');
            if (!empty($languages)) {
                $metadata['language'] = (string)$languages[0];
            }
            
            // Extract publisher
            $publishers = $opf->xpath('//dc:publisher');
            if (!empty($publishers)) {
                $metadata['publisher'] = (string)$publishers[0];
            }
            
            // Extract subject/genre
            $subjects = $opf->xpath('//dc:subject');
            if (!empty($subjects)) {
                $subjectList = [];
                foreach ($subjects as $subject) {
                    $subjectList[] = (string)$subject;
                }
                $metadata['subject'] = implode(', ', $subjectList);
            }
            
            // Extract date (extract year only)
            $dates = $opf->xpath('//dc:date');
            if (!empty($dates)) {
                $fullDate = (string)$dates[0];
                // Extract year from various date formats
                if (preg_match('/(\d{4})/', $fullDate, $matches)) {
                    $metadata['publication_date'] = $this->parsePublicationDate($matches[1]);
                } else {
                    $metadata['publication_date'] = $this->parsePublicationDate($fullDate); // fallback to original if no year found
                }
            }
            
            // Extract identifier (ISBN, etc.)
            $identifiers = $opf->xpath('//dc:identifier[@opf:scheme="ISBN"] | //dc:identifier');
            if (!empty($identifiers)) {
                $metadata['identifier'] = (string)$identifiers[0];
            }

            // Series. EPUB has no standard element for it, so both conventions in
            // the wild are read: the calibre:series meta that most tools write,
            // and the EPUB 3 belongs-to-collection refines pair. Without this the
            // series column and the whole OPDS series facet stayed empty for
            // every EPUB -- only comics ever set a series.
            $seriesMeta = $opf->xpath('//*[local-name()="meta"][@name="calibre:series"]/@content');
            if (!empty($seriesMeta)) {
                $metadata['series'] = trim((string)$seriesMeta[0]);
            }
            $seriesIndex = $opf->xpath('//*[local-name()="meta"][@name="calibre:series_index"]/@content');
            if (!empty($seriesIndex)) {
                $metadata['series_index'] = (float)(string)$seriesIndex[0];
            }

            if (empty($metadata['series'])) {
                $collection = $opf->xpath('//*[local-name()="meta"][@property="belongs-to-collection"]');
                if (!empty($collection)) {
                    $metadata['series'] = trim((string)$collection[0]);
                    $id = (string)$collection[0]['id'];
                    if ($id !== '') {
                        $position = $opf->xpath(
                            '//*[local-name()="meta"][@refines="#' . $id . '"][@property="group-position"]'
                        );
                        if (!empty($position)) {
                            $metadata['series_index'] = (float)(string)$position[0];
                        }
                    }
                }
            }
            
            return $metadata;
            
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getMimeType($format) {
        $mimeTypes = [
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'cbr' => 'application/vnd.comicbook-rar',
            'txt' => 'text/plain'
        ];
        
        return $mimeTypes[$format] ?? 'application/octet-stream';
    }

    /**
     * Parse various date formats into YYYY-MM-DD format for publication_date field
     */
    private function parsePublicationDate(?string $dateValue): ?string {
        if (empty($dateValue)) {
            return null;
        }

        $dateValue = trim($dateValue);

        // Handle 4-digit year format (most common case)
        if (preg_match('/^\d{4}$/', $dateValue)) {
            return $dateValue . '-01-01'; // Default to January 1st
        }

        // Handle YYYY-MM format
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            return "$year-$month-01"; // Default to 1st of month
        }

        // Handle YYYY-MM-DD format (already correct)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            $day = sprintf('%02d', intval($matches[3]));
            
            // Validate the date
            if (checkdate(intval($month), intval($day), intval($year))) {
                return "$year-$month-$day";
            }
        }

        // Extract year from any string containing a 4-digit year
        if (preg_match('/(\d{4})/', $dateValue, $matches)) {
            $year = $matches[1];
            // Validate year range
            if (intval($year) >= 1000 && intval($year) <= 2099) {
                return "$year-01-01"; // Default to January 1st
            }
        }

        // Could not parse the date
        return null;
    }

    /**
     * Remove book metadata from database
     */
    public function removeBookFromDatabase(Node $file) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return;
        }

        $userId = $user->getUID();
        $fileId = $file->getId();
        
        // Get metadata ID before deletion for cleanup
        $metadataId = $this->getMetadataId($userId, $fileId);
        
        if ($metadataId) {
            // Clean up all related records
            $this->cleanupBookReferences($metadataId, $userId);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->executeStatement();
            
            $this->logger->info('Successfully removed book metadata and related records', ['file_path' => $file->getPath()]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove metadata for file', ['file_path' => $file->getPath(), 'exception' => $e]);
        }
    }

    /**
     * Get metadata ID for a user's file
     */
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
            $this->logger->error('Failed to retrieve metadata ID', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Clean up all related records when a book is deleted
     */
    public function cleanupBookReferences(int $metadataId, string $userId) {
        try {
            // Begin transaction to ensure atomic cleanup
            $this->db->beginTransaction();

            // Step 1: Get all document hashes for this book from hash mappings
            $documentHashes = $this->getDocumentHashesForBook($metadataId, $userId);
            
            if (!empty($documentHashes)) {
                // Step 2: Remove sync progress for all hashes of this book
                $this->removeSyncProgressForHashes($documentHashes, $userId);
                
                $this->logger->info('Removed sync progress for document hashes', ['hash_count' => count($documentHashes), 'metadata_id' => $metadataId]);
            }

            // Step 3: Remove all hash mappings for this book
            $removedMappings = $this->removeHashMappings($metadataId, $userId);
            
            if ($removedMappings > 0) {
                $this->logger->info('Removed hash mappings', ['mappings_removed' => $removedMappings, 'metadata_id' => $metadataId]);
            }

            $this->db->commit();
            
            $this->logger->info('Successfully cleaned up all references for book', ['metadata_id' => $metadataId, 'user' => $userId]);
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to cleanup book references', ['metadata_id' => $metadataId, 'exception' => $e]);
        }
    }

    /**
     * Clean up orphaned metadata entries (files that no longer exist in filesystem)
     */
    private function cleanupOrphanedMetadata(string $userId): int {
        try {
            $cleanedCount = 0;
            $userFolder = $this->rootFolder->getUserFolder($userId);

            // Get all metadata entries for the user
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id', 'file_id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            while ($row = $result->fetch()) {
                $metadataId = $row['id'];
                $fileId = $row['file_id'];

                // Check if file still exists in filesystem
                $files = $userFolder->getById($fileId);
                if (empty($files)) {
                    // File no longer exists, clean up all references
                    $this->cleanupBookReferences($metadataId, $userId);

                    // Remove the metadata entry itself
                    $deleteQb = $this->db->getQueryBuilder();
                    $deleteQb->delete('koreader_metadata')
                        ->where($deleteQb->expr()->eq('id', $deleteQb->createNamedParameter($metadataId)))
                        ->executeStatement();

                    $cleanedCount++;
                    $this->logger->info('Cleaned up orphaned metadata entry', ['file_id' => $fileId, 'metadata_id' => $metadataId]);
                }
            }
            $result->closeCursor();

            if ($cleanedCount > 0) {
                $this->logger->info('Cleaned up orphaned metadata entries', ['count' => $cleanedCount, 'user' => $userId]);
            }

            return $cleanedCount;

        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup orphaned metadata', ['user' => $userId, 'exception' => $e]);
            return 0;
        }
    }

    /**
     * Get all document hashes associated with a book
     */
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
            $this->logger->error('Failed to get document hashes', ['metadata_id' => $metadataId, 'exception' => $e]);
            return [];
        }
    }

    /**
     * Remove sync progress for multiple document hashes
     */
    private function removeSyncProgressForHashes(array $documentHashes, string $userId): int {
        if (empty($documentHashes)) {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_sync_progress')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->in('document_hash', $qb->createNamedParameter($documentHashes, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove sync progress for hashes', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Remove all hash mappings for a book
     */
    private function removeHashMappings(int $metadataId, string $userId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_hash_mapping')
               ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove hash mappings', ['metadata_id' => $metadataId, 'exception' => $e]);
            return 0;
        }
    }

    /**
     * Stores binary + filename hashes for a file in koreader_hash_mapping.
     *
     * $file is always the filename-hash source (KOReader hashes the filename it
     * saved its download as). $binarySource, when given, is hashed for the binary
     * hash instead of $file -- callers pass the opds-optimized mirror there, since
     * that's what OPDS actually serves.
     */
    private function createHashMappingsForFile(Node $file, string $userId, int $metadataId, ?Node $binarySource = null): void {
        try {
            $hashSource = $binarySource ?? $file;
            $binaryHash = $this->hashGenerator->generateBinaryHashFromNode($hashSource);
            $filenameHash = $this->hashGenerator->generateFilenameHashFromNode($file);

            if (!$binaryHash && !$filenameHash) {
                $this->logger->warning('Failed to generate hashes for file', [
                    'file_path' => $file->getPath(),
                    'user_id' => $userId,
                    'metadata_id' => $metadataId
                ]);
                return;
            }

            // Remove existing hash mappings for this metadata record
            $this->removeHashMappings($metadataId, $userId);

            $now = date('Y-m-d H:i:s');

            // Insert binary hash mapping
            if ($binaryHash) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('koreader_hash_mapping')
                    ->values([
                        'user_id' => $qb->createNamedParameter($userId),
                        'document_hash' => $qb->createNamedParameter($binaryHash),
                        'hash_type' => $qb->createNamedParameter('binary'),
                        'metadata_id' => $qb->createNamedParameter($metadataId),
                        'created_at' => $qb->createNamedParameter($now)
                    ])
                    ->executeStatement();
            }

            // Insert filename hash mapping
            if ($filenameHash) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('koreader_hash_mapping')
                    ->values([
                        'user_id' => $qb->createNamedParameter($userId),
                        'document_hash' => $qb->createNamedParameter($filenameHash),
                        'hash_type' => $qb->createNamedParameter('filename'),
                        'metadata_id' => $qb->createNamedParameter($metadataId),
                        'created_at' => $qb->createNamedParameter($now)
                    ])
                    ->executeStatement();
            }

            $this->logger->info('Created hash mappings for file', [
                'file_path' => $file->getPath(),
                'user_id' => $userId,
                'metadata_id' => $metadataId,
                'binary_hash' => $binaryHash,
                'filename_hash' => $filenameHash
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create hash mappings', [
                'file_path' => $file->getPath(),
                'user_id' => $userId,
                'metadata_id' => $metadataId,
                'exception' => $e->getMessage()
            ]);
        }
    }

    // ====================== OPDS OPTIMIZED MIRROR ======================

    /** @return array{enabled:bool,max_width:int,max_height:int,grayscale:bool,quality:int} */
    private function getOptimizeSettings(string $userId): array {
        return [
            'enabled' => $this->config->getValueString($userId, 'koreader_companion', 'opds_optimize_enabled', 'yes') === 'yes',
            'max_width' => (int)$this->config->getValueString($userId, 'koreader_companion', 'opds_optimize_max_width', (string)self::DEFAULT_OPTIMIZE_MAX_WIDTH),
            'max_height' => (int)$this->config->getValueString($userId, 'koreader_companion', 'opds_optimize_max_height', (string)self::DEFAULT_OPTIMIZE_MAX_HEIGHT),
            'grayscale' => $this->config->getValueString($userId, 'koreader_companion', 'opds_optimize_grayscale', 'no') === 'yes',
            'quality' => self::DEFAULT_OPTIMIZE_QUALITY,
        ];
    }

    private function getBooksFolder(string $userId): Node {
        $folderName = $this->config->getValueString($userId, 'koreader_companion', 'folder', 'eBooks');
        return $this->rootFolder->getUserFolder($userId)->get($folderName);
    }

    private function getOrCreateOptimizedFolder(Node $booksFolder): Folder {
        try {
            return $booksFolder->get(self::OPTIMIZED_FOLDER_NAME);
        } catch (NotFoundException $e) {
            return $booksFolder->newFolder(self::OPTIMIZED_FOLDER_NAME);
        }
    }

    /** Non-epub formats have no optimizer, so this is just a copy of the original. */
    private function buildOptimizedBytes(Node $file, string $userId, string $format): string {
        $original = $file->getContent();

        if ($format !== 'epub') {
            return $original;
        }

        $settings = $this->getOptimizeSettings($userId);
        if (!$settings['enabled']) {
            return $original;
        }

        try {
            return $this->epubOptimizer->optimize($original, $settings);
        } catch (\Throwable $e) {
            // A book must never become undownloadable because optimization failed.
            $this->logger->warning('EPUB optimization failed, storing original bytes instead', [
                'app' => 'koreader_companion',
                'file_id' => $file->getId(),
                'exception' => $e,
            ]);
            return $original;
        }
    }

    /**
     * Ensures the "Author - Title (opt).{format}" mirror copy exists, rebuilding it when $force is set.
     *
     * @return array{node: ?Node, created: bool} `created` tells callers whether the hash
     *         mapping (which must match the mirror's bytes) needs regenerating too.
     */
    private function ensureOptimizedCopy(Node $file, string $userId, string $format, bool $force = false): array {
        try {
            $optimizedFolder = $this->getOrCreateOptimizedFolder($this->getBooksFolder($userId));
            $existing = $this->findOptimizedNode($userId, (int)$file->getId(), $format, $optimizedFolder);

            if ($existing !== null) {
                if ($force) {
                    $existing->putContent($this->buildOptimizedBytes($file, $userId, $format));
                }
                $existing = $this->renameOptimizedNode($existing, $optimizedFolder, $this->optimizedFileName($file, $userId, $format));
                $this->config->setValueString($userId, 'koreader_companion', self::OPTIMIZED_NODE_KEY_PREFIX . $file->getId(), (string)$existing->getId());
                return ['node' => $existing, 'created' => $force];
            }

            $name = $this->uniqueOptimizedName($optimizedFolder, $this->optimizedFileName($file, $userId, $format));
            $node = $optimizedFolder->newFile($name, $this->buildOptimizedBytes($file, $userId, $format));
            $this->config->setValueString($userId, 'koreader_companion', self::OPTIMIZED_NODE_KEY_PREFIX . $file->getId(), (string)$node->getId());
            return ['node' => $node, 'created' => true];
        } catch (\Throwable $e) {
            $this->logger->warning('Could not create opds-optimized copy', [
                'app' => 'koreader_companion',
                'file_id' => $file->getId(),
                'exception' => $e,
            ]);
            return ['node' => null, 'created' => false];
        }
    }

    /**
     * The mirror node for a source file: via the remembered node id, or -- for
     * copies made before files were named after the book -- the legacy
     * "{fileId}.{format}" name.
     */
    private function findOptimizedNode(string $userId, int $fileId, string $format, ?Folder $optimizedFolder = null): ?Node {
        $nodeId = (int)$this->config->getValueString($userId, 'koreader_companion', self::OPTIMIZED_NODE_KEY_PREFIX . $fileId, '0');
        if ($nodeId > 0) {
            $nodes = $this->rootFolder->getUserFolder($userId)->getById($nodeId);
            if (!empty($nodes)) {
                return $nodes[0];
            }
        }
        try {
            $folder = $optimizedFolder ?? $this->getBooksFolder($userId)->get(self::OPTIMIZED_FOLDER_NAME);
            return $folder->get($fileId . '.' . $format);
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /** "Author - Title (opt).ext", from the indexed metadata (file name if there is none). */
    private function optimizedFileName(Node $file, string $userId, string $format): string {
        $title = '';
        $author = '';
        try {
            $qb = $this->db->getQueryBuilder();
            $row = $qb->select('title', 'author')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
                ->executeQuery()->fetch();
            if ($row) {
                $title = (string)($row['title'] ?? '');
                $author = (string)($row['author'] ?? '');
            }
        } catch (\Throwable $e) {
            // Fall back to the file name below.
        }
        return $this->buildOptimizedName($title, $author, pathinfo($file->getName(), PATHINFO_FILENAME), $format);
    }

    private function buildOptimizedName(string $title, string $author, string $fallbackName, string $format): string {
        $clean = static fn(string $v): string => trim(preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u', ' ', $v) ?? '');
        $title = $clean($title);
        $author = $clean($author);
        if ($title === '') {
            $base = $clean($fallbackName);
        } else {
            $base = ($author !== '' && strcasecmp($author, 'Unknown') !== 0) ? "$author - $title" : $title;
        }
        if ($base === '') {
            $base = 'book';
        }
        // Keep well inside common 255-byte filename limits.
        $base = mb_strcut($base, 0, 200, 'UTF-8');
        return $base . ' (opt).' . $format;
    }

    /** Appends " (2)", " (3)"... so two books with the same author and title don't collide. */
    private function uniqueOptimizedName(Folder $folder, string $name): string {
        if (!$folder->nodeExists($name)) {
            return $name;
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = substr($name, 0, -(strlen($ext) + 1));
        for ($n = 2; $folder->nodeExists("$base ($n).$ext"); $n++);
        return "$base ($n).$ext";
    }

    /** Renames the mirror node when the book's title/author changed since it was created. */
    private function renameOptimizedNode(Node $node, Folder $folder, string $desired): Node {
        if ($node->getName() === $desired) {
            return $node;
        }
        $target = $this->uniqueOptimizedName($folder, $desired);
        try {
            return $node->move($folder->getPath() . '/' . $target);
        } catch (\Throwable $e) {
            $this->logger->debug('Could not rename opds-optimized copy', ['app' => 'koreader_companion', 'exception' => $e]);
            return $node;
        }
    }

    /**
     * Wipes the mirror so the next reconciliation pass rebuilds it -- there is no
     * per-setting staleness tracking, so this is how a settings change takes effect.
     */
    public function resetOptimizedLibrary(string $userId): void {
        try {
            $this->getBooksFolder($userId)->get(self::OPTIMIZED_FOLDER_NAME)->delete();
        } catch (\Exception $e) {
            // Nothing to reset, or the folder isn't there -- fine either way.
        }
    }

    /**
     * OPDS-only download: resolves the mirror first, falling back to downloadBook()
     * when it hasn't been built yet (e.g. still in the 'pending' indexing window).
     *
     * Kept separate from downloadBook(): the in-app reader and the `bookFile` route
     * must keep serving the original, full-fidelity file. Only OPDS gets the mirror.
     */
    public function downloadOptimizedBook($book, $format) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Unauthorized'], 401);
        }

        try {
            $file = $this->findOptimizedNode($user->getUID(), (int)$book['id'], $format);
            if ($file === null) {
                return $this->downloadBook($book, $format);
            }

            $response = new StreamResponse($file->fopen('r'));
            $response->addHeader('Content-Type', $this->getMimeType($format));
            $response->addHeader('Content-Disposition', $this->contentDisposition($file->getName()));
            $response->addHeader('Content-Length', $file->getSize());
            return $response;
        } catch (\Throwable $e) {
            return $this->downloadBook($book, $format);
        }
    }

    // ====================== FACETED BROWSING METHODS ======================

    /**
     * Get authors with book counts for faceted browsing
     */
    public function getAuthors($page = 1, $perPage = 50, $skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['author', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('author'))
               ->andWhere($qb->expr()->neq('author', $qb->createNamedParameter('')))
               ->groupBy('author')
               ->orderBy('author', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get authors', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get total count of unique authors
     */
    public function getAuthorsCount($skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('author')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('author'))
               ->andWhere($qb->expr()->neq('author', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get authors count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get books by specific author with pagination
     */
    public function getBooksByAuthor($author, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('author', $qb->createNamedParameter($author)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);

            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by author', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get count of books by specific author
     */
    public function getBooksByAuthorCount($author) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('author', $qb->createNamedParameter($author)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by author count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get series with book counts for faceted browsing
     */
    public function getSeries($page = 1, $perPage = 50, $skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['series', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('series'))
               ->andWhere($qb->expr()->neq('series', $qb->createNamedParameter('')))
               ->groupBy('series')
               ->orderBy('series', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get series', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get total count of unique series
     */
    public function getSeriesCount($skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('series')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('series'))
               ->andWhere($qb->expr()->neq('series', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get series count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get books by specific series with pagination (ordered by series_index)
     */
    public function getBooksBySeries($seriesName, $page = 1, $perPage = 20) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('series', $qb->createNamedParameter($seriesName)))
               ->orderBy('series_index', 'ASC')
               ->addOrderBy('title', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by series', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get count of books by specific series
     */
    public function getBooksBySeriesCount($seriesName) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('series', $qb->createNamedParameter($seriesName)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by series count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get genres/subjects with book counts for faceted browsing
     */
    public function getGenres($page = 1, $perPage = 50, $skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['subject', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('subject'))
               ->andWhere($qb->expr()->neq('subject', $qb->createNamedParameter('')))
               ->groupBy('subject')
               ->orderBy('subject', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get genres', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get total count of unique genres/subjects
     */
    public function getGenresCount($skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('subject')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('subject'))
               ->andWhere($qb->expr()->neq('subject', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get genres count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get books by specific genre/subject with pagination
     */
    public function getBooksByGenre($genre, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($genre)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'author':
                    $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by genre', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get count of books by specific genre/subject
     */
    public function getBooksByGenreCount($genre) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($genre)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by genre count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get formats with book counts for faceted browsing
     */
    public function getFormats($page = 1, $perPage = 50, $skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['file_format', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('file_format'))
               ->andWhere($qb->expr()->neq('file_format', $qb->createNamedParameter('')))
               ->groupBy('file_format')
               ->orderBy('file_format', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get formats', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get total count of unique formats
     */
    public function getFormatsCount($skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('file_format')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('file_format'))
               ->andWhere($qb->expr()->neq('file_format', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get formats count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get books by specific format with pagination
     */
    public function getBooksByFormat($format, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_format', $qb->createNamedParameter($format)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'author':
                    $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by format', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get count of books by specific format
     */
    public function getBooksByFormatCount($format) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_format', $qb->createNamedParameter($format)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by format count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get languages with book counts for faceted browsing
     */
    public function getLanguages($page = 1, $perPage = 50, $skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['language', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('language'))
               ->andWhere($qb->expr()->neq('language', $qb->createNamedParameter('')))
               ->groupBy('language')
               ->orderBy('language', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get languages', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get total count of unique languages
     */
    public function getLanguagesCount($skipMetadataUpdate = false) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();

        // Ensure metadata is up to date (unless skipped)
        if (!$skipMetadataUpdate) {
            $this->ensureMetadataUpToDate($userId);
        }

        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('language')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('language'))
               ->andWhere($qb->expr()->neq('language', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get languages count', ['exception' => $e]);
            return 0;
        }
    }

    /**
     * Get books by specific language with pagination
     */
    public function getBooksByLanguage($language, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'author':
                    $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by language', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get count of books by specific language
     */
    public function getBooksByLanguageCount($language) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get books by language count', ['exception' => $e]);
            return 0;
        }
    }
}