<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Edition\Mode;
use Meridian\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'editions:archive', description: "Freeze today's compact edition into data/archive (daily cron, after fetch)")]
final class EditionsArchiveCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $edition = $this->services->builder()->build(
            $this->services->registry(),
            $this->services->itemCache()->load(),
            new \DateTimeImmutable(),
            Mode::Compact,
        );

        if ($edition->total() === 0) {
            $output->writeln('<error>nothing to archive — the edition is empty (run fetch first?)</error>');

            return Command::FAILURE;
        }

        $date = $this->services->archive()->save($edition);
        $output->writeln(sprintf('archived %s — %d articles in %d sections', $date, $edition->total(), count($edition->sections)));

        return Command::SUCCESS;
    }
}
