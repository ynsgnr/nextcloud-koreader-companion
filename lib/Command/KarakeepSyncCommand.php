<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Command;

use OCA\KoreaderCompanion\Service\Karakeep\KarakeepSettings;
use OCA\KoreaderCompanion\Service\Karakeep\KarakeepSyncService;
use OCP\IUserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'koreader:karakeep:sync',
    description: 'Import new Karakeep articles for a user now',
)]
class KarakeepSyncCommand extends Command {

    public function __construct(
        private KarakeepSyncService $sync,
        private KarakeepSettings $settings,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void {
        $this->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = (string)$input->getArgument('user');
        if (!$this->userManager->userExists($userId)) {
            $output->writeln("<error>No such user: {$userId}</error>");
            return Command::FAILURE;
        }

        $connection = $this->settings->connection($userId);
        if ($connection === null) {
            $output->writeln('<error>Karakeep is not set up for this user. Run koreader:karakeep:configure.</error>');
            return Command::FAILURE;
        }

        try {
            $result = $this->sync->sync($userId, $connection);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln("Imported {$result->imported}, not crawled yet {$result->notCrawledYet}, failed {$result->failed}.");

        return $result->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
