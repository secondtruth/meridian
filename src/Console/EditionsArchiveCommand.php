<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Edition\Archive;
use Meridian\Edition\Builder;
use Meridian\Edition\Mode;
use Meridian\Feed\ItemCache;
use Meridian\Registry\Registry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'editions:archive', description: "Freeze today's compact edition into data/archive (daily cron, after fetch)")]
final class EditionsArchiveCommand extends Command
{
    public function __construct(private readonly string $dataDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = Registry::load($this->dataDir . '/sources');
        $items = (new ItemCache($this->dataDir . '/cache/items.json'))->load();
        $edition = (new Builder())->build($registry, $items, new \DateTimeImmutable(), Mode::Compact);

        if ($edition->total() === 0) {
            $output->writeln('<error>nothing to archive — the edition is empty (run fetch first?)</error>');

            return Command::FAILURE;
        }

        $date = (new Archive($this->dataDir . '/archive'))->save($edition);
        $output->writeln(sprintf('archived %s — %d articles in %d sections', $date, $edition->total(), count($edition->sections)));

        return Command::SUCCESS;
    }
}
