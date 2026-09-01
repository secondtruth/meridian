<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sources:validate', description: 'Validate the dataset against the classification rules')]
final class SourcesValidateCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->services->registry();
        $topics = $this->services->topics();
        $collections = $this->services->collections();
        $problems = [
            ...$registry->validate(),
            ...$topics->validate(),
            ...$collections->validate($registry),
        ];

        if ($problems !== []) {
            $output->writeln('<error>dataset invalid:</error>');
            foreach ($problems as $problem) {
                $output->writeln("  {$problem}");
            }

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'OK — %d sources, %d focus topics, %d collections, all classification rules satisfied',
            $registry->count(),
            $topics->count(),
            $collections->count(),
        ));

        return Command::SUCCESS;
    }
}
