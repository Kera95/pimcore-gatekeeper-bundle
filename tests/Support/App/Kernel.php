<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Support\App;

use Pimcore\Bundle\CustomReportsBundle\PimcoreCustomReportsBundle;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Pimcore\Kernel as PimcoreKernel;
use Tsf\GatekeeperBundle\TsfGatekeeperBundle;

/**
 * Kernel of the throwaway project the functional suite runs in: Pimcore core, the Custom Reports
 * bundle and this bundle, configured by config/packages/gatekeeper_test.yaml next to this file.
 */
final class Kernel extends PimcoreKernel
{
    public function registerBundlesToCollection(BundleCollection $collection): void
    {
        $collection->addBundle(new PimcoreCustomReportsBundle());
        $collection->addBundle(new TsfGatekeeperBundle());
    }
}
