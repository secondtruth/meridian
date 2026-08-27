<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

/**
 * A coarse bucket on a -3..+3 axis, used for diversity accounting and
 * display. The boundaries reflect the European understanding of the
 * spectrum: "centre" means the European centre, not the US centre.
 */
enum Band: int
{
    case Left = 0;
    case CentreLeft = 1;
    case Centre = 2;
    case CentreRight = 3;
    case Right = 4;

    public static function of(float $value): self
    {
        return match (true) {
            $value < -1.75 => self::Left,
            $value < -0.5 => self::CentreLeft,
            $value <= 0.5 => self::Centre,
            $value <= 1.75 => self::CentreRight,
            default => self::Right,
        };
    }
}
