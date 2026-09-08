<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle;

use Doctrine\DBAL\Connection;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Tsf\GatekeeperBundle\Service\ResultStore;

class Installer extends SettingsStoreAwareInstaller
{
    public function __construct(
        BundleInterface $bundle,
        private readonly Connection $connection,
    ) {
        parent::__construct($bundle);
    }

    public function install(): void
    {
        $this->connection->executeStatement(ResultStore::createTableSql());

        parent::install();
    }

    public function uninstall(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . ResultStore::TABLE);

        parent::uninstall();
    }

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }
}
