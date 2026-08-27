<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Account\Store;
use Meridian\Auth\PendingLogins;
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
    public function __construct(private readonly string $rootDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = Store::at($this->rootDir);
        if (!$store->db->exists()) {
            $output->writeln('no account store — nothing to prune');

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $removed = $store->prune($now) + (new PendingLogins($store->db))->purgeExpired($now);

        $output->writeln(sprintf('OK — %d expired records removed', $removed));

        return Command::SUCCESS;
    }
}
