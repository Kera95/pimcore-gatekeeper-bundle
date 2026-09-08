<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service;

use Pimcore\Model\DataObject\Concrete;
use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Model\Evaluation;
use Tsf\GatekeeperBundle\Model\Profile;
use Tsf\GatekeeperBundle\Model\Result;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessChecker;

use function count;
use function in_array;

/**
 * Pure calculation: object + rule in, Evaluation out. Nothing is written anywhere.
 */
class Evaluator
{
    public function __construct(
        private readonly FieldReader $fieldReader,
        private readonly EmptinessChecker $emptiness,
        private readonly LanguageProvider $languages,
    ) {
    }

    public function evaluate(Concrete $object, ClassRule $rule): Evaluation
    {
        $class = $object->getClass();
        $results = [];

        foreach ($rule->getProfiles() as $profile) {
            $localizedFields = [];
            foreach ($profile->getRequired() as $field) {
                if ($this->fieldReader->isLocalized($class, $field)) {
                    $localizedFields[] = $field;
                }
            }

            $languages = count($localizedFields) > 0 ? $this->resolveLanguages($profile) : [''];

            foreach ($languages as $language) {
                $missing = [];
                foreach ($profile->getRequired() as $field) {
                    $definition = $this->fieldReader->getDefinition($class, $field);
                    if ($definition === null) {
                        // unknown field: cannot be filled; tsf:gatekeeper:validate reports the config error
                        $missing[] = $field;

                        continue;
                    }

                    $isLocalized = in_array($field, $localizedFields, true);
                    $value = $this->fieldReader->read($object, $field, $isLocalized ? $language : null);

                    if (!$this->emptiness->isFilled($definition, $value)) {
                        $missing[] = $field;
                    }
                }

                $results[] = new Result(
                    $profile->getName(),
                    $language,
                    $profile->getRequired(),
                    $missing,
                    $profile->getThreshold()
                );
            }
        }

        return new Evaluation((string) $object->getClassName(), $results);
    }

    /**
     * @return string[]
     */
    private function resolveLanguages(Profile $profile): array
    {
        $languages = $profile->getLanguages();
        if (count($languages) > 0) {
            return $languages;
        }

        $valid = $this->languages->getValidLanguages();

        return count($valid) > 0 ? $valid : [''];
    }
}
