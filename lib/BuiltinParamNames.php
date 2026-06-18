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
            case 'substr':
                return ['string', 'offset', 'length'];
            case 'str_pad':
                return ['string', 'length', 'pad_string', 'pad_type'];
            case 'str_replace':
            case 'str_ireplace':
                return ['search', 'replace', 'subject', 'count'];
            case 'parse_str':
                return ['string', 'array'];
            case 'sort':
            case 'rsort':
            case 'asort':
            case 'arsort':
            case 'ksort':
            case 'krsort':
            case 'natsort':
            case 'natcasesort':
            case 'usort':
            case 'uasort':
            case 'uksort':
                return ['array', 'flags'];
            case 'array_push':
                return ['array'];
            case 'array_slice':
                return ['array', 'offset', 'length', 'preserve_keys'];
            case 'array_chunk':
                return ['array', 'length', 'preserve_keys'];
            case 'similar_text':
                return ['string1', 'string2', 'percent'];
            case 'settype':
                return ['var', 'type'];
            case 'register_shutdown_function':
                return ['function', 'parameter'];
            case 'header_register_callback':
                return ['callback'];
            case 'headers_sent':
                return ['filename', 'line'];
            case 'number_format':
                return ['num', 'decimals', 'decimal_separator', 'thousands_separator'];
            case 'modf':
                return ['num', 'num2'];
            case 'frexp':
                return ['arg1', 'exp'];
            case 'ldexp':
                return ['num', 'exp'];
            case 'touch':
                return ['filename', 'mtime', 'atime'];
            case 'getenv':
                return ['name', 'local_only'];
            case 'define':
                return ['constant_name', 'value', 'case_insensitive'];
            case 'vsprintf':
                return ['format', 'args'];
            case 'sscanf':
                return ['string', 'format'];
            case 'vfscanf':
            case 'fscanf':
                return ['stream', 'format'];
            case 'fprintf':
                return ['stream', 'format'];
            case 'flock':
                return ['stream', 'operation', 'wouldblock'];
            case 'get_resources':
                return ['resource_type'];
            case 'get_defined_constants':
                return ['categorize'];
            case 'intdiv':
                return ['num1', 'num2'];
            case 'hex2bin':
                return ['data', 'strict'];
            case 'base64_decode':
                return ['string', 'strict'];
            case 'resetaslazyghost':
                return ['object', 'initializer', 'options'];
            case 'exit':
            case 'die':
                return ['status', 'message'];
            case 'http_build_query':
                return ['data', 'numeric_prefix', 'arg_separator', 'encoding_type'];
            case 'json_decode':
                return ['json', 'associative', 'depth', 'flags'];
            case 'str_contains':
                return ['haystack', 'needle'];
            case 'preg_match':
                return ['pattern', 'subject', 'matches', 'flags', 'offset'];
            case 'fopen':
                return ['filename', 'mode', 'use_include_path', 'context'];
            case 'parse_url':
                return ['url', 'component'];
            case 'getopt':
                return ['short_options', 'long_options', 'rest_index'];
            case 'is_callable':
                return ['value', 'syntax_only', 'callable_name'];
            case 'iterator_to_array':
                return ['iterator', 'preserve_keys'];
            case 'hrtime':
                return ['as_number'];
        }

        return null;
    }
}
