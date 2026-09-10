<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperBundle\Service\Report\AssetWriter;
use Tsf\GatekeeperBundle\Service\Report\ReportBuilder;
use Tsf\GatekeeperBundle\Service\ResultStore;

use function count;
use function in_array;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:report',
    description: 'Prints the completeness report from the result table (table, csv or md) and optionally writes it into the asset tree'
)]
final class ReportCommand extends Command
{
    public function __construct(
        private readonly ResultStore $store,
        private readonly ReportBuilder $builder,
        private readonly AssetWriter $assetWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Only this class')
            ->addOption('profile-name', 'p', InputOption::VALUE_REQUIRED, 'Only this profile (--profile is taken by Symfony)')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Only this language')
            ->addOption('below', 'b', InputOption::VALUE_REQUIRED, 'Only rows with a score below this value')
            ->addOption('only-failed', 'f', InputOption::VALUE_NONE, 'Only rows below their threshold')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table, csv or md', 'table')
            ->addOption('summary', 's', InputOption::VALUE_NONE, 'Print one line per class, profile and language (objects, average score, complete, failing) instead of the object rows')
            ->addOption('asset', null, InputOption::VALUE_NONE, 'Write the configured formats into the asset tree (report.asset must be enabled)')
            ->addOption('timestamp', null, InputOption::VALUE_NONE, 'With --asset: append -YYYYmmdd-HHMM to the filenames instead of overwriting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['table', 'csv', 'md'], true)) {
            $io->error('--format must be table, csv or md.');

            return Command::INVALID;
        }

        $class = $input->getOption('class');

        if ($input->getOption('summary')) {
            $this->renderSummary($input, $output, $io, $format);
        } else {
            $this->renderRows($input, $output, $io, $format);
        }

        if ($input->getOption('asset')) {
            if (!$this->assetWriter->isEnabled()) {
                $io->error('report.asset.enabled is false; enable it in tsf_gatekeeper.yaml to write assets.');

                return Command::FAILURE;
            }

            $written = $this->assetWriter->writeAll($class, (bool) $input->getOption('timestamp'));
            $io->success(count($written) > 0 ? 'Written: ' . implode(', ', $written) : 'Nothing to write.');
        }

        return Command::SUCCESS;
    }

    private function renderSummary(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $format): void
    {
        if ($input->getOption('below') !== null || $input->getOption('only-failed')) {
            $io->warning('--below and --only-failed do not apply to --summary; the totals cover all rows.');
        }

        $summary = $this->store->fetchSummary($input->getOption('class'), $input->getOption('profile-name'), $input->getOption('language'));

        if ($format === 'csv') {
            $output->write($this->builder->summaryCsv($summary));

            return;
        }
        if ($format === 'md') {
            $output->writeln($this->builder->summaryTableMarkdown($summary));

            return;
        }
        if (count($summary) === 0) {
            $io->warning('No results yet. Save an object or run tsf:gatekeeper:recalculate.');

            return;
        }

        $table = $this->builder->summaryTable($summary);
        $output->writeln('');
        $consoleTable = new Table($output);
        $consoleTable->setStyle('compact');
        $consoleTable->setHeaders($table['header']);
        $consoleTable->setRows($table['rows']);
        $consoleTable->render();
        $output->writeln('');
    }

    private function renderRows(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $format): void
    {
        $rows = $this->store->fetchRows(
            $input->getOption('class'),
            $input->getOption('profile-name'),
            $input->getOption('language'),
            $input->getOption('below') !== null ? (int) $input->getOption('below') : null,
            (bool) $input->getOption('only-failed')
        );

        if ($format === 'csv') {
            $output->write($this->builder->csv($rows));
        } elseif ($format === 'md') {
            $output->writeln($this->builder->markdown($rows));
        } else {
            $this->renderTable($rows, $output, $io);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderTable(array $rows, OutputInterface $output, SymfonyStyle $io): void
    {
        if (count($rows) === 0) {
            $io->warning('No results yet. Save an object or run tsf:gatekeeper:recalculate.');

            return;
        }

        foreach ($this->builder->sections($rows) as $section) {
            $output->writeln('');
            $output->writeln(sprintf(' <options=bold>%s</>', $section['title']));
            $table = new Table($output);
            $table->setStyle('compact');
            $table->setHeaders($section['header']);
            $table->setRows($section['rows']);
            $table->render();
        }
        $output->writeln('');
    }
}
