<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

/**
 * Compile-time PHP 8 attribute metadata (#1936, #3206, #3340).
 */
final class AttributeEntry
{
    /**
     * @param list<array{name: ?string, value: mixed}> $args compile-time constant constructor args
     * @param ?string                                 $validationError delayed internal-attribute error
     *                                                                  ({@see #[\DelayedTargetValidation]}, #26241)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
        public bool $isRepeated = false,
        public ?string $validationError = null,
    ) {
    }

    /**
     * @param list<AttributeEntry> $entries
     *
     * @return list<string>
     */
    public static function namesFromList(array $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            if ($entry instanceof self) {
                $names[] = $entry->name;
            }
        }

        return $names;
    }
}
