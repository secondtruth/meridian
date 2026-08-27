<?php

declare(strict_types=1);

namespace Meridian\Feed;

/**
 * Minimal RSS 2.0 / Atom parser via SimpleXML — enough for the curated
 * feed set; a full feed library is not warranted at prototype stage.
 */
final class FeedParser
{
    private const SUMMARY_MAX = 280;

    /**
     * @return list<Item> items without sourceId (filled in by the fetcher)
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($doc === false) {
            throw new \RuntimeException('not parseable as XML');
        }

        $name = $doc->getName();
        if ($name === 'rss' || $name === 'RDF') {
            return $this->parseRss($doc);
        }
        if ($name === 'feed') {
            return $this->parseAtom($doc);
        }

        throw new \RuntimeException("unknown feed root element <{$name}>");
    }

    /** @return list<Item> */
    private function parseRss(\SimpleXMLElement $doc): array
    {
        // RSS 2.0 nests items in <channel>; RSS 1.0 (RDF) has them top-level.
        $entries = $doc->channel->item ?? $doc->item;
        $items = [];
        foreach ($entries as $entry) {
            $title = trim((string) $entry->title);
            $link = trim((string) $entry->link);
            if ($title === '' || $link === '') {
                continue;
            }
            $dc = $entry->children('http://purl.org/dc/elements/1.1/');
            $items[] = new Item(
                sourceId: '',
                title: html_entity_decode($title, ENT_QUOTES | ENT_HTML5),
                link: $link,
                summary: $this->summarize((string) $entry->description),
                published: $this->parseDate((string) ($entry->pubDate ?: $dc->date)),
            );
        }

        return $items;
    }

    /** @return list<Item> */
    private function parseAtom(\SimpleXMLElement $doc): array
    {
        $items = [];
        foreach ($doc->entry as $entry) {
            $title = trim((string) $entry->title);
            $link = '';
            foreach ($entry->link as $l) {
                $rel = (string) $l['rel'];
                if ($rel === '' || $rel === 'alternate') {
                    $link = (string) $l['href'];
                    break;
                }
            }
            if ($title === '' || $link === '') {
                continue;
            }
            $items[] = new Item(
                sourceId: '',
                title: html_entity_decode($title, ENT_QUOTES | ENT_HTML5),
                link: $link,
                summary: $this->summarize((string) ($entry->summary ?: $entry->content)),
                published: $this->parseDate((string) ($entry->published ?: $entry->updated)),
            );
        }

        return $items;
    }

    private function parseDate(string $raw): \DateTimeImmutable
    {
        if ($raw !== '') {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Exception) {
                // fall through to "now"
            }
        }

        return new \DateTimeImmutable();
    }

    private function summarize(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        // WordPress feeds append "The post <title> appeared first on <site>."
        $text = preg_replace('/The post .* appeared first on .*$/u', '', $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > self::SUMMARY_MAX) {
            $text = mb_substr($text, 0, self::SUMMARY_MAX);
            $lastSpace = mb_strrpos($text, ' ');
            if ($lastSpace !== false) {
                $text = mb_substr($text, 0, $lastSpace);
            }
            $text .= '…';
        }

        return $text;
    }
}
