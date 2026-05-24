<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * PHP parameter names for VM builtins (named arguments, issue #168).
 */
final class BuiltinParamNames
{
    /** @var array<string, list<string>> */
    private const MAP = [
        'strlen' => ['string'],
    ];

    /**
     * @return list<string>|null
     */
    public static function forFunction(string $name): ?array
    {
        $lc = strtolower($name);
        if (!isset(self::MAP[$lc])) {
            return null;
        }

        return self::MAP[$lc];
    }
}
