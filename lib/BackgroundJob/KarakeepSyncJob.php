<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\BackgroundJob;

use OCA\KoreaderCompanion\Service\KarakeepSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/** Runs the Karakeep import for every user who switched it on. */
class KarakeepSyncJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private IUserConfig $config,
        private KarakeepSyncService $sync,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(5 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        foreach ($this->config->searchUsersByValueString('koreader_companion', 'karakeep_enabled', 'yes') as $userId) {
            try {
                $this->sync->sync($userId);
            } catch (\Throwable $e) {
                $this->logger->error('Karakeep sync failed', [
                    'app' => 'koreader_companion',
                    'userId' => $userId,
                    'exception' => $e,
                ]);
            }
        }
    }
}
