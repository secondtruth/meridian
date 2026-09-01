<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\I18n\Translator;
use Meridian\Services;
use Meridian\Spectrum\Labels;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sources:list', description: 'List all sources with their spectrum ratings')]
final class SourcesListCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->services->registry();
        $labels = new Labels($this->services->translator(Translator::DEFAULT));

        $table = new Table($output);
        $table->setHeaders(['Quelle', 'Land', 'Perspektive', 'Einordnung', 'Parteifamilie', 'Staat', 'Verlässl.', 'Konfidenz']);
        foreach ($registry->all() as $source) {
            $table->addRow([
                $source->name,
                $source->country,
                $labels->perspective($source->perspective),
                $labels->summary($source->rating),
                $labels->partyFamily($source->rating->partyFamily),
                $labels->stateInfluence($source->rating->stateInfluence),
                Labels::reliabilityDots($source->rating->reliability),
                $source->rating->confidence,
            ]);
        }
        $table->render();
        $output->writeln(sprintf(
            '%d Quellen · Achsen: ökonomisch / GAL–TAN / EU-Haltung, jeweils -3..+3',
            $registry->count(),
        ));

        return Command::SUCCESS;
    }
}
