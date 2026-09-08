<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle;

use Composer\InstalledVersions;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Tsf\GatekeeperBundle\DependencyInjection\TsfGatekeeperExtension;

use function dirname;

class TsfGatekeeperBundle extends AbstractPimcoreBundle
{
    public function getNiceName(): string
    {
        return 'TSF Gatekeeper Bundle';
    }

    public function getDescription(): string
    {
        return 'Scores DataObject completeness per class, profile and language on save, reports it and optionally gates publishing.';
    }

    public function getComposerPackageName(): string
    {
        return 'kerimkaralic/pimcore-gatekeeper-bundle';
    }

    public function getVersion(): string
    {
        if (!InstalledVersions::isInstalled($this->getComposerPackageName())) {
            return '';
        }

        return ltrim((string) InstalledVersions::getPrettyVersion($this->getComposerPackageName()), 'v');
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new TsfGatekeeperExtension();
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function getInstaller(): Installer
    {
        return $this->container->get(Installer::class);
    }
}
