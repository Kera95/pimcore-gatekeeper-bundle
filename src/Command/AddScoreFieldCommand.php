<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Command;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Numeric;
use Pimcore\Model\DataObject\ClassDefinition\Layout;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:add-score-field',
    description: 'Adds a Numeric 0-100 field to a class so the aggregate score shows up in grids and the generated API'
)]
final class AddScoreFieldCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'DataObject class name, e.g. Product')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Field name', 'completeness')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Field title', 'Completeness %')
            ->addOption('panel', null, InputOption::VALUE_REQUIRED, 'Name of the layout panel to append to (default: the layout root)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $className = (string) $input->getArgument('class');
        $name = (string) $input->getOption('name');

        $class = ClassDefinition::getByName($className);
        if ($class === null) {
            $io->error(sprintf('Class "%s" does not exist.', $className));

            return Command::FAILURE;
        }
        if (isset($class->getFieldDefinitions()[$name])) {
            $io->error(sprintf('Class "%s" already has a field "%s".', $className, $name));

            return Command::FAILURE;
        }

        $root = $class->getLayoutDefinitions();
        if (!$root instanceof Layout) {
            $io->error('The class has no layout definition.');

            return Command::FAILURE;
        }

        $target = $root;
        if ($input->getOption('panel') !== null) {
            $target = $this->findLayout($root, (string) $input->getOption('panel'));
            if ($target === null) {
                $io->error(sprintf('No layout named "%s" in class "%s".', $input->getOption('panel'), $className));

                return Command::FAILURE;
            }
        }

        $field = new Numeric();
        $field->setName($name);
        $field->setTitle((string) $input->getOption('title'));
        $field->setTooltip('Aggregate completeness score written by TsfGatekeeperBundle on save');
        $field->setInteger(true);
        $field->setUnsigned(true);
        $field->setMinValue(0.0);
        $field->setMaxValue(100.0);
        $field->setNoteditable(true);
        $field->setIndex(true);

        $target->addChild($field);
        // the field definition cache was filled by getFieldDefinition() above; rebuild it from the layout
        $class->setFieldDefinitions(null);
        $class->save();

        $io->success(sprintf('Added Numeric field "%s" to class "%s".', $name, $className));
        $io->listing([
            'Set score_field: ' . $name . ' under tsf_gatekeeper.classes.' . $className,
            'Run bin/console cache:clear',
            'On 2026.x also run bin/console generic-data-index:update:index -c ' . $class->getId() . ' so the grid can sort on it',
            'Run bin/console tsf:gatekeeper:recalculate --save to fill it for existing objects',
        ]);

        return Command::SUCCESS;
    }

    private function findLayout(Layout $layout, string $name): ?Layout
    {
        if ($layout->getName() === $name) {
            return $layout;
        }
        foreach ($layout->getChildren() as $child) {
            if ($child instanceof Layout) {
                $found = $this->findLayout($child, $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
