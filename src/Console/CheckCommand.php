<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Points\Ledger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php bin/console plugins:points:check` : contrôle des invariants du journal de tous les contrats (Ledger::checkAll, docs/conception.md,
 * « Invariants »). LECTURE SEULE : rien n'est écrit, pas même le cache. Code de sortie non nul au premier écart (à brancher sur une supervision).
 *
 * Le nom porte le préfixe `plugins:points:` exigé par le chargeur des commandes de plugin (src/Glpi/Console/CommandLoader.php:261-280 en 12.0.1).
 */
final class CheckCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('plugins:points:check')
            ->setDescription('Check the invariants of the points journal of every contract (read only; exit code 1 if anything is wrong)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Plugin::isPluginActive('points')) {
            $output->writeln('<error>The Points plugin is not active: nothing done</error>');

            return Command::FAILURE;
        }
        $problems = Ledger::checkAll();
        foreach ($problems as $contractsId => $list) {
            foreach ($list as $problem) {
                $output->writeln('<error>' . OutputFormatter::escape(sprintf('contract %d: %s', $contractsId, $problem)) . '</error>');
            }
        }
        if ($problems !== []) {
            $output->writeln('<error>' . sprintf('%d contract(s) with an inconsistency', count($problems)) . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('All contracts are consistent');

        return Command::SUCCESS;
    }
}
