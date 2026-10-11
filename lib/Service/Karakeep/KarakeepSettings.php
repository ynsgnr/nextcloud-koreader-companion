<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Karakeep;

use OCA\KoreaderCompanion\AppInfo\Application;
use OCP\Config\IUserConfig;

/** A user's Karakeep import settings and progress, kept in their app preferences. */
class KarakeepSettings {

    public function __construct(
        private IUserConfig $config,
    ) {
    }

    /** Null unless the import is switched on and fully set up. */
    public function connection(string $userId): ?KarakeepConnection {
        $url = $this->read($userId, 'karakeep_url');
        $apiKey = $this->read($userId, 'karakeep_api_key');
        $listId = $this->read($userId, 'karakeep_list_id');

        if (!$this->isEnabled($userId) || $url === '' || $apiKey === '' || $listId === '') {
            return null;
        }

        return new KarakeepConnection(rtrim($url, '/'), $apiKey, $listId);
    }

    /**
     * An empty $apiKey keeps the stored one.
     *
     * @throws \InvalidArgumentException when $url is not an http(s) address
     */
    public function save(string $userId, bool $enabled, string $url, string $apiKey, string $listId): void {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://[^\s/]+#i', $url)) {
            throw new \InvalidArgumentException('The Karakeep URL must start with http:// or https://');
        }

        $this->write($userId, 'karakeep_url', $url);
        $this->write($userId, 'karakeep_list_id', trim($listId));
        if (trim($apiKey) !== '') {
            $this->config->setValueString($userId, Application::APP_ID, 'karakeep_api_key', trim($apiKey), false, IUserConfig::FLAG_SENSITIVE);
        }
        $this->write($userId, 'karakeep_enabled', $enabled ? 'yes' : 'no');
    }

    /** What the settings page shows; the API key itself never leaves the server. */
    public function summary(string $userId): array {
        return [
            'karakeep_enabled' => $this->isEnabled($userId) ? 'yes' : 'no',
            'karakeep_url' => $this->read($userId, 'karakeep_url'),
            'karakeep_list_id' => $this->read($userId, 'karakeep_list_id'),
            'karakeep_has_api_key' => $this->read($userId, 'karakeep_api_key') !== '' ? 'yes' : 'no',
        ];
    }

    /** @return string[] */
    public function userIdsWithImportEnabled(): array {
        return iterator_to_array($this->config->searchUsersByValueString(Application::APP_ID, 'karakeep_enabled', 'yes'), false);
    }

    public function importState(string $userId): KarakeepImportState {
        return KarakeepImportState::fromJson($this->read($userId, 'karakeep_state', '{}'));
    }

    public function saveImportState(string $userId, KarakeepImportState $state): void {
        $this->write($userId, 'karakeep_state', $state->toJson());
    }

    private function isEnabled(string $userId): bool {
        return $this->read($userId, 'karakeep_enabled', 'no') === 'yes';
    }

    private function read(string $userId, string $key, string $default = ''): string {
        return $this->config->getValueString($userId, Application::APP_ID, $key, $default);
    }

    private function write(string $userId, string $key, string $value): void {
        $this->config->setValueString($userId, Application::APP_ID, $key, $value);
    }
}
