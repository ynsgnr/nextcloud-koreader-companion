<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Command;

use OCA\KoreaderCompanion\Service\KarakeepSyncService;
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
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = (string)$input->getArgument('user');
        if (!$this->userManager->userExists($userId)) {
            $output->writeln("<error>No such user: {$userId}</error>");
            return Command::FAILURE;
        }
        if (!$this->sync->isConfigured($userId)) {
            $output->writeln('<error>Karakeep is not configured for this user. Run koreader:karakeep:configure.</error>');
            return Command::FAILURE;
        }

        try {
            $result = $this->sync->sync($userId);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln("Imported {$result['imported']}, skipped {$result['skipped']}, failed {$result['failed']}.");

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
