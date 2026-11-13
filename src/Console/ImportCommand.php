<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Points\Domain\ImportRules;
use GlpiPlugin\Points\Import;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php bin/console plugins:points:import fichier.csv [--apply]` : import des soldes du BPM Kiwi (docs/conception.md).
 *
 * Le nom porte le préfixe `plugins:points:` exigé par le chargeur des commandes de plugin (src/Glpi/Console/CommandLoader.php:261-280
 * en 12.0.1 : sans lui la commande est ignorée avec un avertissement).
 *
 * Simulation par défaut ; `--apply` écrit. Tout le fichier est contrôlé avant la première écriture : une ligne invalide
 * (format, contrat, dates, doublon) donne un code de sortie non nul et rien n'est écrit. Pas de session utilisateur en console :
 * les écritures portent `users_id` = 0 (voir Import).
 */
final class ImportCommand extends AbstractCommand
{
    /** Taille maximale du fichier (octets) : un export du BPM Kiwi tient dans quelques dizaines de kilo-octets. */
    private const MAX_BYTES = 5_000_000;

    protected function configure(): void
    {
        $this->setName('plugins:points:import')
            ->setDescription('Import the opening points balances of the BPM Kiwi (dry run unless --apply)')
            ->addArgument('file', InputArgument::REQUIRED, 'CSV file (UTF-8, ";" separator): contrat;solde;debut;fin;report;libelle')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the envelopes (without it: dry run, nothing is written)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Garde en profondeur : le chargeur ne charge que les plugins actifs (Plugin::getPlugins, src/Plugin.php:2679 en 12.0.1), mais la classe peut être appelée autrement
        if (!\Plugin::isPluginActive('points')) {
            $output->writeln('<error>The Points plugin is not active: nothing done</error>');

            return Command::FAILURE;
        }
        $path = (string) $input->getArgument('file');
        if (!is_file($path) || !is_readable($path)) {
            $output->writeln('<error>' . OutputFormatter::escape('File not found or not readable: ' . ImportRules::displayCell($path, 200)) . '</error>');

            return Command::FAILURE;
        }
        if (filesize($path) > self::MAX_BYTES) {
            $output->writeln('<error>The file is too large</error>');

            return Command::FAILURE;
        }
        $parsed = ImportRules::parse((string) file_get_contents($path));
        $errors = $parsed['errors'];
        $plan   = Import::plan($parsed['rows']);

        foreach ($plan as $item) {
            if ($item['status'] === Import::STATUS_ERROR) {
                $errors[] = ['line' => $item['row']['line'], 'message' => $item['message']];
            }
        }
        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $apply = (bool) $input->getOption('apply');
        $output->writeln($apply ? 'Mode: APPLY' : 'Mode: dry run (nothing is written, use --apply to write)');
        foreach ($plan as $item) {
            $label = match ($item['status']) {
                Import::STATUS_NEW  => 'NEW  ',
                Import::STATUS_SKIP => 'SKIP ',
                default             => 'ERROR',
            };
            $output->writeln(OutputFormatter::escape(sprintf('line %d: %s %s', $item['row']['line'], $label, Import::describeRow($item['row']))));
            foreach ($item['warnings'] as $warning) {
                $output->writeln('<comment>' . OutputFormatter::escape('  warning: ' . $warning) . '</comment>');
            }
        }
        foreach ($errors as $error) {
            $output->writeln('<error>' . OutputFormatter::escape(sprintf('line %d: %s', $error['line'], $error['message'])) . '</error>');
        }
        $new  = count(array_filter($plan, static fn (array $i): bool => $i['status'] === Import::STATUS_NEW));
        $skip = count(array_filter($plan, static fn (array $i): bool => $i['status'] === Import::STATUS_SKIP));
        if ($errors !== []) {
            $output->writeln('<error>' . sprintf('%d error(s): nothing written', count($errors)) . '</error>');

            return Command::FAILURE;
        }
        if (!$apply) {
            $output->writeln(sprintf('%d envelope(s) to create, %d already imported', $new, $skip));

            return Command::SUCCESS;
        }

        $created = 0;
        $skipped = $skip;
        foreach (Import::apply($plan, $path) as $result) {
            if ($result['outcome'] === 'failed') {
                $output->writeln('<error>' . OutputFormatter::escape(sprintf('line %d: refused: %s', $result['line'], $result['message'])) . '</error>');
                $output->writeln(sprintf('<error>Stopped: %d created before the failure; the import can be run again (imported lines are skipped)</error>', $created));

                return Command::FAILURE;
            }
            $result['outcome'] === 'created' ? $created++ : $skipped++;
        }
        $output->writeln(sprintf('%d envelope(s) created, %d skipped (already imported)', $created, $skipped));

        return Command::SUCCESS;
    }
}
