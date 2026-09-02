<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Auth\PendingLogins;
use Meridian\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'accounts:prune',
    description: 'Delete expired sessions and reading history past each reader\'s retention setting',
)]
final class AccountsPruneCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->services->store();
        if (!$store->db->exists()) {
            $output->writeln('no account store — nothing to prune');

            return Command::SUCCESS;
        }

        $now = $this->services->clock()->now();
        $removed = $store->prune($now) + (new PendingLogins($store->db))->purgeExpired($now);

        $output->writeln(sprintf('OK — %d expired records removed', $removed));

        return Command::SUCCESS;
    }
}
