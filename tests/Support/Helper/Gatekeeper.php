<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Support\Helper;

use Codeception\Module;
use Pimcore;
use Pimcore\Bootstrap;
use Pimcore\Tests\Support\Util\Autoloader;
use Symfony\Component\Filesystem\Filesystem;
use Tsf\GatekeeperBundle\Tests\Support\App\Kernel;
use Tsf\GatekeeperBundle\Tests\Support\Fixture\ClassFixtures;

use function sprintf;

/**
 * Codeception module for the functional suite. Listed before Pimcore's own module, so that
 * _initialize() has prepared the project constants and environment by the time Pimcore's module
 * boots the kernel; _beforeSuite() then installs the bundles and creates the test classes in the
 * freshly created database.
 */
final class Gatekeeper extends Module
{
    public function _initialize(): void
    {
        $dsn = getenv('PIMCORE_TEST_DB_DSN') ?: ($_SERVER['PIMCORE_TEST_DB_DSN'] ?? '');
        if ($dsn === '') {
            throw new \RuntimeException(
                'PIMCORE_TEST_DB_DSN is not set. The functional suite needs a database it may drop and ' .
                'recreate, e.g. PIMCORE_TEST_DB_DSN=mysql://root:root@127.0.0.1:3306/tsf_gatekeeper_test'
            );
        }

        $root = dirname(__DIR__) . '/App';
        $fs = new Filesystem();
        // a stale compiled container would ignore config changes (APP_DEBUG is off)
        $fs->remove($root . '/var/cache');
        $fs->mkdir([$root . '/var/config', $root . '/var/classes', $root . '/public/var']);

        foreach (['APP_ENV' => 'test', 'PIMCORE_TEST' => '1', 'PIMCORE_TEST_DB_DSN' => $dsn] as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        // Pimcore 2026 validates the product registration when the container is compiled, unless the
        // "needs install" marker is present. The test project is never registered.
        if (($_SERVER['PIMCORE_PRODUCT_KEY'] ?? '') === '') {
            $fs->touch($root . '/var/config/needs-install.lock');
        }

        if (!defined('PIMCORE_PROJECT_ROOT')) {
            define('PIMCORE_PROJECT_ROOT', $root);
        }
        // the vendor directory is the bundle's own one or the surrounding project's, never App/vendor
        if (!defined('PIMCORE_COMPOSER_PATH')) {
            define('PIMCORE_COMPOSER_PATH', TSF_GATEKEEPER_VENDOR_DIR);
        }
        if (!defined('PIMCORE_COMPOSER_FILE_PATH')) {
            define('PIMCORE_COMPOSER_FILE_PATH', dirname(TSF_GATEKEEPER_VENDOR_DIR));
        }
        if (!defined('PIMCORE_KERNEL_CLASS')) {
            define('PIMCORE_KERNEL_CLASS', Kernel::class);
        }
        // in test mode Pimcore would otherwise generate classes into vendor/pimcore/pimcore/tests/_output
        if (!defined('PIMCORE_CLASS_DIRECTORY')) {
            define('PIMCORE_CLASS_DIRECTORY', $root . '/var/classes');
        }

        Bootstrap::setProjectRoot();
        Bootstrap::bootstrap();

        Autoloader::addNamespace('Pimcore\\Model\\DataObject', PIMCORE_CLASS_DIRECTORY . '/DataObject');
    }

    public function _beforeSuite(array $settings = []): void
    {
        $kernel = Pimcore::getKernel();
        if ($kernel === null) {
            throw new \RuntimeException('The Pimcore kernel is not booted; is the Pimcore module enabled after this one?');
        }

        foreach (['PimcoreCustomReportsBundle', 'TsfGatekeeperBundle'] as $name) {
            $installer = $kernel->getBundle($name)->getInstaller();
            if ($installer !== null && !$installer->isInstalled()) {
                $this->debug(sprintf('[GATEKEEPER] Installing %s', $name));
                $installer->install();
            }
        }

        ClassFixtures::create();
    }
}
