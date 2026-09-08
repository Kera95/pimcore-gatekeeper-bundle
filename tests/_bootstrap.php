<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Tsf\GatekeeperBundle\Tests\Support\PimcoreStubKernel;

$autoloaders = [
    // standalone checkout of the bundle
    dirname(__DIR__) . '/vendor/autoload.php',
    // installed as a composer path package inside a Pimcore project
    dirname(__DIR__, 4) . '/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;

        break;
    }
}

if (!class_exists(ClassLoader::class)) {
    throw new RuntimeException(
        'Could not locate the composer autoloader. Run "composer install" in the bundle or in the surrounding project.'
    );
}

// The bundle is consumed as a composer path package, so the surrounding project's autoloader knows
// the src/ namespace but not autoload-dev of this package. Registering the test namespace here keeps
// the suite runnable both standalone and from inside a project.
$testLoader = new ClassLoader();
$testLoader->addPsr4('Tsf\\GatekeeperBundle\\Tests\\', __DIR__);
$testLoader->register();

// The unit suite never boots a real Pimcore kernel. A stub is enough for the few core helpers
// (Pimcore\Model\Element\Service) that reach for the container to dispatch their events.
PimcoreStubKernel::register();
