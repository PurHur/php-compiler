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
            case 'modf':
                return ['num', 'num2'];
            case 'frexp':
                return ['arg1', 'exp'];
            case 'ldexp':
                return ['num', 'exp'];
            case 'touch':
                return ['filename', 'time', 'atime'];
            case 'getenv':
                return ['name', 'local_only'];
            case 'define':
                return ['constant_name', 'value', 'case_insensitive'];
        }

        return null;
    }
}
