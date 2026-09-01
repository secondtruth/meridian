<?php

declare(strict_types=1);

namespace Meridian\Feed;

/** JSON file cache decoupling feed fetching (cron) from page rendering. */
final readonly class ItemCache
{
    public function __construct(private string $path)
    {
    }

    /** @param list<Item> $items */
    public function save(array $items): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new \RuntimeException("cannot create cache directory {$dir}");
        }
        $data = json_encode(
            array_map(fn (Item $item) => $item->toArray(), $items),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($this->path, $data) === false) {
            throw new \RuntimeException("cannot write cache file {$this->path}");
        }
    }

    /** @return list<Item> */
    public function load(): array
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException("no item cache at {$this->path} — run 'meridian fetch' first");
        }
        $data = json_decode(file_get_contents($this->path) ?: '', true, flags: JSON_THROW_ON_ERROR);

        return array_map(Item::fromArray(...), $data);
    }

    /**
     * The items, or none: pages render before the first cron run and
     * show their empty state instead of failing.
     *
     * @return list<Item>
     */
    public function loadOrEmpty(): array
    {
        try {
            return $this->load();
        } catch (\RuntimeException) {
            return [];
        }
    }

    public function lastFetchedAt(): ?\DateTimeImmutable
    {
        $mtime = is_file($this->path) ? filemtime($this->path) : false;

        return $mtime === false ? null : new \DateTimeImmutable('@' . $mtime);
    }
}
