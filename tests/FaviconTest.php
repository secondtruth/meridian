<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Feed\FaviconMirror;
use PHPUnit\Framework\TestCase;

final class FaviconTest extends TestCase
{
    public function testExtensionComesFromTheBytesNotTheUrl(): void
    {
        self::assertSame('png', FaviconMirror::extensionFor("\x89PNG\r\n\x1a\n rest"));
        self::assertSame('ico', FaviconMirror::extensionFor("\x00\x00\x01\x00 rest"));
        self::assertSame('svg', FaviconMirror::extensionFor('<?xml version="1.0"?><svg xmlns="…"/>'));
        self::assertNull(FaviconMirror::extensionFor('<!doctype html><title>404</title>'), 'an error page is not an icon');
    }

    public function testIconUrlPrefersTheDeclaredLink(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/a.css">'
            . '<link rel="shortcut icon" href="/static/icon.png"></head></html>';

        self::assertSame(
            'https://example.org/static/icon.png',
            FaviconMirror::iconUrl($html, 'https://example.org/'),
        );
    }

    public function testIconUrlIsNullWithoutADeclaredIcon(): void
    {
        self::assertNull(FaviconMirror::iconUrl('<link rel="canonical" href="/x">', 'https://example.org/'));
    }

    public function testRelativeHrefsResolveAgainstThePage(): void
    {
        self::assertSame('https://example.org/icon.ico', FaviconMirror::resolveUrl('/icon.ico', 'https://example.org/news/'));
        self::assertSame('https://example.org/news/icon.ico', FaviconMirror::resolveUrl('icon.ico', 'https://example.org/news/'));
        self::assertSame('https://cdn.example.org/i.png', FaviconMirror::resolveUrl('//cdn.example.org/i.png', 'https://example.org/'));
        self::assertSame('http://other.example/i.png', FaviconMirror::resolveUrl('http://other.example/i.png', 'https://example.org/'));
    }
}
