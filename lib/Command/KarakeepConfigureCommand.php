<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Command;

use OCP\Config\IUserConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'koreader:karakeep:configure',
    description: 'Set up the Karakeep article import for a user',
)]
class KarakeepConfigureCommand extends Command {

    public function __construct(
        private IUserConfig $config,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setHelp('Imports bookmarks from one Karakeep list as EPUBs into the user\'s book folder. Use --disable to turn it off.')
            ->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Karakeep base URL, e.g. https://karakeep.example.com')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Karakeep API key')
            ->addOption('list-id', null, InputOption::VALUE_REQUIRED, 'Id of the Karakeep list to import')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Turn the import off');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = (string)$input->getArgument('user');
        if (!$this->userManager->userExists($userId)) {
            $output->writeln("<error>No such user: {$userId}</error>");
            return Command::FAILURE;
        }

        if ($input->getOption('disable')) {
            $this->config->setValueString($userId, 'koreader_companion', 'karakeep_enabled', 'no');
            $output->writeln('Karakeep import disabled.');
            return Command::SUCCESS;
        }

        $url = (string)$input->getOption('url');
        $apiKey = (string)$input->getOption('api-key');
        $listId = (string)$input->getOption('list-id');
        if ($url === '' || $apiKey === '' || $listId === '') {
            $output->writeln('<error>--url, --api-key and --list-id are all required.</error>');
            return Command::FAILURE;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $output->writeln('<error>--url must start with http:// or https://</error>');
            return Command::FAILURE;
        }

        $this->config->setValueString($userId, 'koreader_companion', 'karakeep_url', $url);
        $this->config->setValueString($userId, 'koreader_companion', 'karakeep_api_key', $apiKey, false, IUserConfig::FLAG_SENSITIVE);
        $this->config->setValueString($userId, 'koreader_companion', 'karakeep_list_id', $listId);
        $this->config->setValueString($userId, 'koreader_companion', 'karakeep_enabled', 'yes');
        $output->writeln('Karakeep import enabled. Run koreader:karakeep:sync, or wait for the background job.');

        return Command::SUCCESS;
    }
}
