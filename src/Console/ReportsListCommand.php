<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Account\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'reports:list',
    description: 'Show open reader objections to a classification',
)]
final class ReportsListCommand extends Command
{
    public function __construct(private readonly string $rootDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'resolve',
            null,
            InputOption::VALUE_REQUIRED,
            'Mark the report with this ID as reviewed',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = Store::at($this->rootDir);
        if (!$store->db->exists()) {
            $output->writeln('no account store — no reports');

            return Command::SUCCESS;
        }

        $resolve = $input->getOption('resolve');
        if ($resolve !== null) {
            $done = $store->reports->resolve((int) $resolve, new \DateTimeImmutable());
            $output->writeln($done
                ? sprintf('report %d marked as reviewed', (int) $resolve)
                : sprintf('<error>no open report with ID %d</error>', (int) $resolve));

            return $done ? Command::SUCCESS : Command::FAILURE;
        }

        $reports = $store->reports->open();
        if ($reports === []) {
            $output->writeln('no open reports');

            return Command::SUCCESS;
        }

        foreach ($reports as $report) {
            $sourceAnchored = $report['url'] === '';
            $output->writeln(sprintf(
                "\n<info>#%d</info>  %s  <comment>%s</comment>  source: %s%s",
                $report['id'],
                $report['created_at'],
                $report['kind'],
                $report['source_id'],
                $sourceAnchored ? '  (source-anchored)' : '  topic: ' . $report['topic'],
            ));
            if (!$sourceAnchored) {
                $output->writeln('  ' . $report['title']);
                $output->writeln('  ' . $report['url']);
            }
            if (trim((string) $report['note']) !== '') {
                $output->writeln('  note: ' . $report['note']);
            }
        }

        $output->writeln(sprintf(
            "\n%d open — review against METHODOLOGY.md, then: bin/meridian reports:list --resolve=ID",
            count($reports),
        ));

        return Command::SUCCESS;
    }
}
