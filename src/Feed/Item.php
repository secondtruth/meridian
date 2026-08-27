<?php

declare(strict_types=1);

namespace Meridian\Feed;

/** One cached feed entry, linked to its source by ID. */
final readonly class Item
{
    public function __construct(
        public string $sourceId,
        public string $title,
        public string $link,
        public string $summary,
        public \DateTimeImmutable $published,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'link' => $this->link,
            'summary' => $this->summary,
            'published' => $this->published->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, string> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceId: $data['source_id'],
            title: $data['title'],
            link: $data['link'],
            summary: $data['summary'],
            published: new \DateTimeImmutable($data['published']),
        );
    }
}
