<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Command;

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\Evaluator;
use Tsf\GatekeeperBundle\Service\ResultStore;

use function count;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:recalculate',
    description: 'Evaluates every object of the configured classes and rewrites the result table (objects are not saved unless --save)'
)]
final class RecalculateCommand extends Command
{
    private const BATCH = 200;

    public function __construct(
        private readonly RuleSet $rules,
        private readonly Evaluator $evaluator,
        private readonly ResultStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Only this class')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many objects per class')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Save every object so the score field, versions and the search index are refreshed too (slow)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate and print, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('class');
        $limit = $input->getOption('limit') !== null ? max(0, (int) $input->getOption('limit')) : null;
        $save = (bool) $input->getOption('save');
        $dryRun = (bool) $input->getOption('dry-run');

        $rules = $only !== null ? array_filter([$this->rules->getEnabled((string) $only)]) : $this->rules->enabled();
        if (count($rules) === 0) {
            $io->error($only !== null ? sprintf('Class "%s" is not configured or disabled.', $only) : 'No enabled classes configured.');

            return Command::FAILURE;
        }

        $totalFailed = 0;
        foreach ($rules as $rule) {
            $class = ClassDefinition::getByName($rule->getClassName());
            if ($class === null) {
                $io->error(sprintf('Class "%s" does not exist.', $rule->getClassName()));

                return Command::FAILURE;
            }

            [$count, $failed] = $this->processClass($rule, $class, $limit, $save, $dryRun, $io);
            $totalFailed += $failed;
            $io->writeln(sprintf('%s: %d object(s) evaluated, %d failing%s', $rule->getClassName(), $count, $failed, $dryRun ? ' (dry run)' : ''));
        }

        if (!$dryRun && !$save) {
            $io->note('The result table is current. Score fields on the objects refresh on their next save, or run again with --save.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} evaluated, failing
     */
    private function processClass(ClassRule $rule, ClassDefinition $class, ?int $limit, bool $save, bool $dryRun, SymfonyStyle $io): array
    {
        $listingClass = '\\Pimcore\\Model\\DataObject\\' . $class->getName() . '\\Listing';
        /** @var DataObject\Listing\Concrete $listing */
        $listing = new $listingClass();
        $listing->setUnpublished(true);
        $listing->setObjectTypes([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_VARIANT]);
        $listing->setOrderKey('id');
        $listing->setOrder('ASC');

        $count = 0;
        $failed = 0;
        $offset = 0;

        while (true) {
            $listing->setOffset($offset);
            $listing->setLimit(self::BATCH);
            $objects = $listing->load();
            if (count($objects) === 0) {
                break;
            }

            foreach ($objects as $object) {
                if (!$object instanceof Concrete) {
                    continue;
                }
                if ($limit !== null && $count >= $limit) {
                    return [$count, $failed];
                }

                $evaluation = $this->evaluator->evaluate($object, $rule);
                $count++;
                if (!$evaluation->isPassed()) {
                    $failed++;
                }

                if ($io->isVerbose() || $dryRun) {
                    $io->writeln(sprintf(
                        '  #%d %s: %d %%%s',
                        $object->getId(),
                        $object->getKey(),
                        $evaluation->getAggregateScore(),
                        $evaluation->isPassed() ? '' : ' - ' . $evaluation->describeFailures()
                    ));
                }

                if ($dryRun) {
                    continue;
                }

                if ($save) {
                    // the listener evaluates again, writes the score field and stores the rows
                    $object->save();
                } else {
                    $this->store->save((int) $object->getId(), $evaluation);
                }
            }

            $offset += self::BATCH;
            \Pimcore::collectGarbage();
        }

        return [$count, $failed];
    }
}
