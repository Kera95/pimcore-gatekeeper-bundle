<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperBundle\Service\Config\ClassRuleValidator;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;

use function count;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:validate',
    description: 'Checks the tsf_gatekeeper configuration against the installed classes, fields and languages'
)]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly RuleSet $rules,
        private readonly ClassRuleValidator $validator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rules = $this->rules->all();

        if (count($rules) === 0) {
            $io->warning('No classes configured under tsf_gatekeeper.classes.');

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($rules as $rule) {
            $problems = $this->validator->validate($rule);
            $label = sprintf(
                '%s (%s, gate %s, profiles: %s%s)',
                $rule->getClassName(),
                $rule->isEnabled() ? 'enabled' : 'disabled',
                $rule->getGate()->value,
                implode(', ', $rule->getProfileNames()),
                $rule->getScoreField() !== null ? ', score_field ' . $rule->getScoreField() : ''
            );

            if (count($problems) === 0) {
                $io->writeln(sprintf('<info>OK</info>    %s', $label));

                continue;
            }

            $failed++;
            $io->writeln(sprintf('<error>ERROR</error> %s', $label));
            $io->listing($problems);
        }

        if ($failed > 0) {
            $io->error(sprintf('%d of %d class rule(s) have problems.', $failed, count($rules)));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d class rule(s) are valid.', count($rules)));

        return Command::SUCCESS;
    }
}
