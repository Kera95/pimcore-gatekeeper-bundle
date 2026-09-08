<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Model;

enum Gate: string
{
    case Off = 'off';
    case Warn = 'warn';
    case Block = 'block';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(static fn (self $gate): string => $gate->value, self::cases());
    }
}
