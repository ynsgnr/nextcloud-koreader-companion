<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\BackgroundJob;

use OCA\KoreaderCompanion\AppInfo\Application;
use OCA\KoreaderCompanion\Service\Karakeep\KarakeepSettings;
use OCA\KoreaderCompanion\Service\Karakeep\KarakeepSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Imports new Karakeep articles for every user who switched the import on. */
class KarakeepSyncJob extends TimedJob {

    private const INTERVAL_SECONDS = 5 * 60;

    public function __construct(
        ITimeFactory $time,
        private KarakeepSettings $settings,
        private KarakeepSyncService $sync,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        foreach ($this->settings->userIdsWithImportEnabled() as $userId) {
            $connection = $this->settings->connection($userId);
            if ($connection === null) {
                continue;
            }

            try {
                $this->sync->sync($userId, $connection);
            } catch (\Throwable $e) {
                $this->logger->error('Karakeep import failed', [
                    'app' => Application::APP_ID,
                    'userId' => $userId,
                    'exception' => $e,
                ]);
            }
        }
    }
}
