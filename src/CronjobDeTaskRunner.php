<?php declare(strict_types=1);

namespace CronjobDe\TaskRunner;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CronjobDeTaskRunner extends Plugin
{
    public const CONFIG_TOKEN = 'CronjobDeTaskRunner.config.token';
    public const CONFIG_URL = 'CronjobDeTaskRunner.config.url';
    public const CONFIG_CONNECT = 'CronjobDeTaskRunner.config.connect';
    public const CONFIG_WORKER_OFF = 'CronjobDeTaskRunner.config.adminWorkerOff';
    public const PATH = '/api/cronjob-de/run';

    /** Where the connect link leads: login (or sign-up) at Cronjob.de, then the pre-filled cronjob form. */
    private const CONNECT_BASE = 'https://www.cronjob.de/login?redirect=';

    public function postInstall(InstallContext $installContext): void
    {
        $this->ensureToken();
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        $this->ensureToken();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if ($uninstallContext->keepUserData()) {
            return;
        }
        $config = $this->container->get(SystemConfigService::class);
        foreach ([self::CONFIG_TOKEN, self::CONFIG_URL, self::CONFIG_CONNECT, self::CONFIG_WORKER_OFF] as $key) {
            $config->delete($key);
        }
    }

    /** Creates the secret token on first install and keeps the displayed addresses in sync. */
    private function ensureToken(): void
    {
        $config = $this->container->get(SystemConfigService::class);
        $token = (string) $config->get(self::CONFIG_TOKEN);
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $config->set(self::CONFIG_TOKEN, $token);
        }
        // Default from config.xml; set here too so it also applies after an update from 1.0.0.
        if ($config->get(self::CONFIG_WORKER_OFF) === null) {
            $config->set(self::CONFIG_WORKER_OFF, true);
        }
        self::writeAddresses($config, $token);
    }

    /** Writes the run address and the connect link for $token, only where they changed. */
    public static function writeAddresses(SystemConfigService $config, string $token): void
    {
        $url = self::buildUrl($token);
        if ($config->get(self::CONFIG_URL) !== $url) {
            $config->set(self::CONFIG_URL, $url);
        }
        $connect = self::CONNECT_BASE . rawurlencode('loggedin/cronjob?sw=' . rawurlencode($url));
        if ($config->get(self::CONFIG_CONNECT) !== $connect) {
            $config->set(self::CONFIG_CONNECT, $connect);
        }
    }

    /** The address the cron service calls. APP_URL is the shop's admin/API base address. */
    public static function buildUrl(string $token): string
    {
        $base = (string) ($_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
        return rtrim($base, '/') . self::PATH . '?token=' . rawurlencode($token);
    }
}
