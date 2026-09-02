<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Auth\OidcConfig;
use Meridian\I18n\Translator;
use Meridian\Web\Controller\ContentController;
use Meridian\Web\Request;
use Meridian\Web\Response;
use Meridian\Web\View;
use Meridian\Web\Viewer;
use PHPUnit\Framework\TestCase;

/**
 * The legal pages carry statements with legal weight — these tests pin the
 * routing (canonical paths, permanent alias redirect), the mandatory
 * content (address, § 18 MStV line, supervisory authority), the draft
 * guard (a TODO in the rendered output must surface the banner), and the
 * dynamic identity-provider naming.
 */
final class ContentControllerTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private function dispatch(
        string $path,
        string $locale = 'de',
        ?OidcConfig $oidc = null,
        string $method = 'GET',
    ): ?Response {
        $request = new Request($method, $path, [], []);
        $view = new View(
            self::ROOT . '/templates',
            new Translator($locale, self::ROOT . '/translations'),
            $request,
            Viewer::anonymous($oidc !== null),
        );

        return new ContentController($oidc)->handle($request, $view);
    }

    public function testImpressumRendersCompleteWithoutDraftBanner(): void
    {
        $response = $this->dispatch('/impressum');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Edelweißweg 20', $response->body);
        self::assertStringContainsString('§ 18 Abs. 2 MStV', $response->body);
        self::assertStringContainsString('§ 36 VSBG', $response->body);
        self::assertStringNotContainsString('Entwurf —', $response->body);
        self::assertStringNotContainsString('TODO', $response->body);
    }

    public function testPrivacyRendersCompleteInGerman(): void
    {
        $response = $this->dispatch('/privacy');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Datenschutzerklärung', $response->body);
        self::assertStringContainsString('meridian-lang', $response->body);
        self::assertStringContainsString('Heilbronner Straße 35', $response->body);
        // Deployed on Hetzner infrastructure — hosting section filled,
        // so no placeholder and no draft banner.
        self::assertStringContainsString('Hetzner Online GmbH', $response->body);
        self::assertStringNotContainsString('TODO', $response->body);
        self::assertStringNotContainsString('Entwurf —', $response->body);
    }

    public function testPrivacyRendersInEnglish(): void
    {
        $response = $this->dispatch('/privacy', locale: 'en');
        self::assertNotNull($response);
        self::assertStringContainsString('Privacy Policy', $response->body);
        self::assertStringContainsString('authoritative', $response->body);
    }

    public function testAccountSectionNamesTheConfiguredIdentityProvider(): void
    {
        $oidc = new OidcConfig('https://id.example.org', 'meridian', '');
        $body = $this->dispatch('/privacy', oidc: $oidc)->body;
        self::assertStringContainsString('https://id.example.org', $body);
        self::assertStringContainsString('90 Tage', $body);
    }

    public function testAccountSectionCollapsesWhenNoProviderIsConfigured(): void
    {
        $body = $this->dispatch('/privacy')->body;
        self::assertStringContainsString('kein Identity-Provider konfiguriert', $body);
        self::assertStringNotContainsString('id.example.org', $body);
    }

    public function testDatenschutzAliasRedirectsPermanentlyToPrivacy(): void
    {
        $response = $this->dispatch('/datenschutz');
        self::assertNotNull($response);
        self::assertSame(301, $response->status);
        self::assertSame('/privacy', $response->headers()['Location']);
    }

    public function testFooterLinksLegalPagesUnderTheExactLabels(): void
    {
        $de = $this->dispatch('/impressum')->body;
        self::assertStringContainsString('>Impressum</a>', $de);
        self::assertStringContainsString('>Datenschutz</a>', $de);

        $en = $this->dispatch('/impressum', locale: 'en')->body;
        self::assertStringContainsString('>Imprint</a>', $en);
        self::assertStringContainsString('>Privacy</a>', $en);
    }

    public function testMethodologyRendersInBothLocales(): void
    {
        $de = $this->dispatch('/methodology');
        self::assertNotNull($de);
        self::assertSame(200, $de->status);
        self::assertStringContainsString('Methodik', $de->body);

        $en = $this->dispatch('/methodology', locale: 'en');
        self::assertStringContainsString('Methodology', $en->body);
    }

    public function testUnclaimedPathsAndPostsAreLeftToTheApp(): void
    {
        self::assertNull($this->dispatch('/'));
        self::assertNull($this->dispatch('/sources'));
        self::assertNull($this->dispatch('/impressum', method: 'POST'));
        self::assertNull($this->dispatch('/methodology', method: 'POST'));
    }
}
