<?php

declare(strict_types=1);

namespace Meridian;

use Meridian\Account\Store;
use Meridian\Auth\OidcClient;
use Meridian\Auth\OidcConfig;
use Meridian\Collection\Collections;
use Meridian\Edition\Archive;
use Meridian\Edition\Builder;
use Meridian\Feed\ItemCache;
use Meridian\I18n\Translator;
use Meridian\Registry\Registry;
use Meridian\Registry\Topics;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * The composition root: every long-lived collaborator is built here,
 * once per process, from the project root — the one place that knows
 * where the data lives. Only the two entry points (the web App and
 * bin/meridian) hold an instance; every other class receives what it
 * needs through its constructor, which is what keeps this from turning
 * into a service locator.
 */
final class Services
{
    public readonly string $dataDir;

    private ?Registry $registry = null;
    private ?ItemCache $itemCache = null;
    private ?Builder $builder = null;
    private ?Archive $archive = null;
    private ?Store $store = null;
    private ?OidcConfig $oidcConfig = null;
    private bool $oidcLoaded = false;
    private ?OidcClient $oidcClient = null;

    /**
     * @param ClockInterface $clock the process's one clock: the web entry point
     *                              freezes it into Request::$now, a command
     *                              reads it once at the start of its run
     */
    public function __construct(
        public readonly string $rootDir,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $this->dataDir = $rootDir . '/data';
    }

    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    public function registry(): Registry
    {
        return $this->registry ??= Registry::load($this->dataDir . '/sources');
    }

    public function topics(): Topics
    {
        return Topics::load($this->dataDir . '/topics.yaml');
    }

    public function collections(): Collections
    {
        return Collections::load($this->dataDir . '/collections.yaml');
    }

    public function itemCache(): ItemCache
    {
        return $this->itemCache ??= new ItemCache($this->dataDir . '/cache/items.json');
    }

    public function builder(): Builder
    {
        return $this->builder ??= new Builder();
    }

    public function archive(): Archive
    {
        return $this->archive ??= new Archive($this->dataDir . '/archive');
    }

    public function store(): Store
    {
        return $this->store ??= Store::at($this->rootDir);
    }

    /** Null is the normal state of a deployment without accounts. */
    public function oidcConfig(): ?OidcConfig
    {
        if (!$this->oidcLoaded) {
            $this->oidcConfig = OidcConfig::load($this->rootDir);
            $this->oidcLoaded = true;
        }

        return $this->oidcConfig;
    }

    public function oidcClient(): ?OidcClient
    {
        $config = $this->oidcConfig();
        if ($config === null) {
            return null;
        }

        return $this->oidcClient ??= new OidcClient($config, $this->dataDir . '/cache');
    }

    public function translator(string $locale): Translator
    {
        return new Translator($locale, $this->rootDir . '/translations');
    }

    public function templatesDir(): string
    {
        return $this->rootDir . '/templates';
    }

    public function publicDir(): string
    {
        return $this->rootDir . '/public';
    }
}
