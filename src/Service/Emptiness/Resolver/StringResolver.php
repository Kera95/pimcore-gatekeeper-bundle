<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

use function is_scalar;

/**
 * Text fields: the core uses empty(), which treats "0" as empty. Only whitespace counts here;
 * WYSIWYG content is stripped of tags first so "<p></p>" is empty.
 */
final class StringResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        // Email, Firstname and Lastname extend Input
        return $fieldDefinition instanceof Data\Input
            || $fieldDefinition instanceof Data\Textarea
            || $fieldDefinition instanceof Data\Wysiwyg
            || $fieldDefinition instanceof Data\Password;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return true;
        }

        $text = (string) $value;
        if ($fieldDefinition instanceof Data\Wysiwyg) {
            $text = html_entity_decode(strip_tags($text));
            $text = str_replace("\u{A0}", ' ', $text);
        }

        return trim($text) === '';
    }
}
