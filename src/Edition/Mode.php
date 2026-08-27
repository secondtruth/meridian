<?php

declare(strict_types=1);

namespace Meridian\Edition;

/**
 * Reading modes. Compact is the default and the product's priority: the
 * hard-capped, perspective-balanced daily edition (doom-scrolling
 * protection). Full is the deliberate opt-in showing every classified
 * article of the time window — still finite, still no feed mechanics.
 */
enum Mode: string
{
    case Compact = 'compact';
    case Full = 'full';

    public static function fromQuery(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Compact;
    }
}
