<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Config;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\LanguageProvider;

use function count;
use function in_array;
use function sprintf;

/**
 * Runtime checks the container cannot do: class exists, fields exist, score field is Numeric,
 * languages are configured in the system. Returns human readable problems, empty = fine.
 */
class ClassRuleValidator
{
    public function __construct(
        private readonly FieldReader $fieldReader,
        private readonly LanguageProvider $languages,
    ) {
    }

    /**
     * @return string[]
     */
    public function validate(ClassRule $rule): array
    {
        $class = $this->loadClass($rule->getClassName());
        if ($class === null) {
            return [sprintf('Class "%s" does not exist.', $rule->getClassName())];
        }

        $problems = [];
        $validLanguages = $this->languages->getValidLanguages();

        foreach ($rule->getProfiles() as $profile) {
            foreach ($profile->getRequired() as $field) {
                $problem = $this->fieldReader->describeProblem($class, $field);
                if ($problem !== null) {
                    $problems[] = sprintf('%s/%s: %s', $rule->getClassName(), $profile->getName(), $problem);
                }
            }

            foreach ($profile->getLanguages() as $language) {
                if (count($validLanguages) > 0 && !in_array($language, $validLanguages, true)) {
                    $problems[] = sprintf('%s/%s: language "%s" is not a valid system language (%s).', $rule->getClassName(), $profile->getName(), $language, implode(', ', $validLanguages));
                }
            }
        }

        $scoreField = $rule->getScoreField();
        if ($scoreField !== null) {
            $definition = $this->fieldReader->topLevel($class, $scoreField);
            if ($definition === null) {
                $problems[] = sprintf('%s: score_field "%s" does not exist. Create it with tsf:gatekeeper:add-score-field %s --name=%s', $rule->getClassName(), $scoreField, $rule->getClassName(), $scoreField);
            } elseif (!$definition instanceof Data\Numeric) {
                $problems[] = sprintf('%s: score_field "%s" must be a Numeric field, %s given.', $rule->getClassName(), $scoreField, $definition->getFieldType());
            }
        }

        return $problems;
    }

    protected function loadClass(string $name): ?ClassDefinition
    {
        try {
            return ClassDefinition::getByName($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
