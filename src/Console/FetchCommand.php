<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Feed\Fetcher;
use Meridian\Feed\ItemCache;
use Meridian\Registry\Registry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fetch', description: 'Fetch all source feeds into the local cache (intended for cron)')]
final class FetchCommand extends Command
{
    public function __construct(private readonly string $dataDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = Registry::load($this->dataDir . '/sources');
        $result = (new Fetcher())->fetchAll($registry, function (string $feed, string $message) use ($output): void {
            $output->writeln("<comment>feed failed:</comment> {$feed} — {$message}");
        });

        if ($result['items'] === []) {
            $output->writeln('<error>no items fetched</error>');

            return Command::FAILURE;
        }

        (new ItemCache($this->dataDir . '/cache/items.json'))->save($result['items']);
        $output->writeln(sprintf(
            '%d items cached (%d feeds failed)',
            count($result['items']),
            count($result['failed']),
        ));

        return Command::SUCCESS;
    }
}
