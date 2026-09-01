<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Feed\FaviconMirror;
use Meridian\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'favicons:fetch',
    description: 'Mirror source favicons into public/favicons (intended for cron, after fetch)',
)]
final class FaviconsFetchCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('refresh', null, InputOption::VALUE_NONE, 'Re-fetch icons that are already mirrored');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->services->registry();
        $mirror = new FaviconMirror();
        $dir = $this->services->publicDir() . '/favicons';
        $refresh = (bool) $input->getOption('refresh');

        $stored = 0;
        $kept = 0;
        $missed = 0;
        foreach ($registry->all() as $source) {
            if (!$refresh && glob("{$dir}/{$source->id}.*") !== []) {
                ++$kept;
                continue;
            }
            $filename = $mirror->mirror($source, $dir);
            if ($filename === null) {
                ++$missed;
                $output->writeln("<comment>no icon:</comment> {$source->id}");
                continue;
            }
            ++$stored;
            $output->writeln("stored {$filename}");
        }

        $output->writeln(sprintf('%d stored, %d kept, %d without icon', $stored, $kept, $missed));

        return Command::SUCCESS;
    }
}
