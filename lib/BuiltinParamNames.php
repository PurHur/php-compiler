<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * PHP parameter names for VM builtins (named arguments, issue #168).
 */
final class BuiltinParamNames
{
    /**
     * @return list<string>|null
     */
    public static function forFunction(string $name): ?array
    {
        $lc = strtolower($name);
        switch ($lc) {
            case 'strlen':
                return ['string'];
            case 'parse_str':
                return ['string', 'array'];
            case 'similar_text':
                return ['string1', 'string2', 'percent'];
            case 'settype':
                return ['var', 'type'];
            case 'register_shutdown_function':
                return ['function', 'parameter'];
            case 'headers_sent':
                return ['file', 'line'];
        }

        return null;
    }
}
