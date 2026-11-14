<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Points\Domain\BalanceRules;
use GlpiPlugin\Points\Ledger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php bin/console plugins:points:balances [--all] [--contract=ID]` : contrôle des soldes (Ledger::balances, docs/conception.md,
 * « Contrôle des soldes »). Pour chaque contrat à points, le solde, le crédité, le consommé, le % consommé et la prochaine échéance
 * ENREGISTRÉS sur sa ligne Points sont comparés au recalcul depuis les mouvements (journal des opérations, restes des lots).
 * LECTURE SEULE : rien n'est écrit, pas même le cache. Code de sortie 1 s'il y a un écart (recette, supervision après la bascule) ; 2 pour une
 * erreur d'usage (identifiant invalide, contrat sans points) : une supervision ne les confond pas. Plugin inactif : le chargeur du cœur ne
 * charge pas la commande, la console répond « commande inconnue » avec son propre code 1 ; la garde ci-dessous n'est qu'en profondeur.
 *
 * Le nom porte le préfixe `plugins:points:` exigé par le chargeur des commandes de plugin (src/Glpi/Console/CommandLoader.php:261-280 en 12.0.1).
 */
final class BalancesCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('plugins:points:balances')
            ->setDescription('Recompute the points balance of every contract from its movements and report the gaps with the recorded balance (read only; exit code 1 if any)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also list the contracts without a gap')
            ->addOption('contract', null, InputOption::VALUE_REQUIRED, 'Check this contract only (native contract ID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Plugin::isPluginActive('points')) {
            $output->writeln('<error>The Points plugin is not active: nothing done</error>');

            return Command::INVALID;
        }
        $only = (string) ($input->getOption('contract') ?? '');
        if ($only !== '' && preg_match('/^[1-9]\d*$/', $only) !== 1) {
            $output->writeln('<error>--contract expects a contract ID</error>');

            return Command::INVALID;
        }
        $report = Ledger::balances((int) $only);
        if ($only !== '' && $report === []) {
            $output->writeln('<error>' . sprintf('Contract %d has no points (no Points row, no movement)', (int) $only) . '</error>');

            return Command::INVALID;
        }
        $all    = (bool) $input->getOption('all');
        $wrong  = 0;
        foreach ($report as $line) {
            $label = sprintf('contract %d (%s)', $line['contracts_id'], $line['name']);
            if ($line['gaps'] === []) {
                if ($all) {
                    $output->writeln(OutputFormatter::escape(sprintf('OK   %s: balance %s, credited %s, consumed %s', $label,
                        BalanceRules::format('balance', $line['computed']['balance']),
                        BalanceRules::format('credited', $line['computed']['credited']),
                        BalanceRules::format('consumed', $line['computed']['consumed']))));
                }
                continue;
            }
            $wrong++;
            $output->writeln('<error>' . OutputFormatter::escape('GAP  ' . $label) . '</error>');
            foreach ($line['gaps'] as $gap) {
                $output->writeln('<error>' . OutputFormatter::escape('     ' . BalanceRules::describe($gap)) . '</error>');
            }
        }
        $summary = sprintf('%d contract(s) checked, %d with a gap', count($report), $wrong);
        if ($wrong > 0) {
            $output->writeln('<error>' . $summary . '</error>');

            return Command::FAILURE;
        }
        $output->writeln($summary);

        return Command::SUCCESS;
    }
}
