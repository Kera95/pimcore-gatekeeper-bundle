<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Functional;

use Pimcore;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Tests\Support\FunctionalTestCase;

final class InstallerTest extends FunctionalTestCase
{
    public function testInstallCreatesTheResultTableAndUninstallDropsIt(): void
    {
        $installer = Pimcore::getKernel()->getBundle('TsfGatekeeperBundle')->getInstaller();
        self::assertNotNull($installer);
        self::assertTrue($installer->isInstalled());
        self::assertTrue($this->tableExists());

        try {
            $installer->uninstall();
            self::assertFalse($installer->isInstalled());
            self::assertFalse($this->tableExists());
        } finally {
            // the other tests rely on the table
            $installer->install();
        }

        self::assertTrue($installer->isInstalled());
        self::assertTrue($this->tableExists());
    }

    private function tableExists(): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist([ResultStore::TABLE]);
    }
}
