<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Built-in Traversable marker interface rules (Zend/zend_interfaces.c, issue #13326).
 *
 * User classes may list Traversable only together with Iterator or IteratorAggregate.
 */
final class TraversableSupport
{
    public const INTERFACE_NAME = 'Traversable';
    public const INTERFACE_LC = 'traversable';
    public const ITERATOR_LC = 'iterator';
    public const ITERATOR_AGGREGATE_LC = 'iteratoraggregate';

    public static function isTraversableLc(string $ifaceLc): bool
    {
        return self::INTERFACE_LC === strtolower(ltrim($ifaceLc, '\\'));
    }

    /**
     * @param list<string> $directImplementsLc lowercase names from the implements clause
     */
    public static function rejectsDirectTraversableWithoutIteratorProtocol(array $directImplementsLc): bool
    {
        if (!in_array(self::INTERFACE_LC, $directImplementsLc, true)) {
            return false;
        }

        return !in_array(self::ITERATOR_LC, $directImplementsLc, true)
            && !in_array(self::ITERATOR_AGGREGATE_LC, $directImplementsLc, true);
    }

    public static function directTraversableForbiddenMessage(string $kind, string $displayName): string
    {
        return sprintf(
            '%s %s must implement interface Traversable as part of either Iterator or IteratorAggregate',
            $kind,
            $displayName
        );
    }
}
