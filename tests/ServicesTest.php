<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Services;
use PHPUnit\Framework\TestCase;

/** The composition root builds against the real project layout and hands out one instance per collaborator. */
final class ServicesTest extends TestCase
{
    public function testCollaboratorsAreBuiltOnceFromTheProjectRoot(): void
    {
        $services = new Services(__DIR__ . '/..');

        self::assertGreaterThan(0, $services->registry()->count());
        self::assertSame($services->registry(), $services->registry());
        self::assertSame($services->itemCache(), $services->itemCache());
        self::assertSame($services->store(), $services->store());
        self::assertSame($services->oidcConfig() === null, $services->oidcClient() === null);
        self::assertDirectoryExists($services->templatesDir());
        self::assertDirectoryExists($services->publicDir());
    }
}
