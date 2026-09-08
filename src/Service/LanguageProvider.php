<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service;

use Pimcore\Tool;

/**
 * Thin wrapper around the static Pimcore helpers so callers can be unit tested without a kernel
 */
class LanguageProvider
{
    /**
     * @return string[]
     */
    public function getValidLanguages(): array
    {
        return array_values(Tool::getValidLanguages());
    }
}
