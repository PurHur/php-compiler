<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

/**
 * PECL yaml predefined constants (yaml.c MINIT / libyaml enums; #27873).
 */
final class YamlConstants
{
    public const ANY_SCALAR_STYLE = 0;

    public const PLAIN_SCALAR_STYLE = 1;

    public const SINGLE_QUOTED_SCALAR_STYLE = 2;

    public const DOUBLE_QUOTED_SCALAR_STYLE = 3;

    public const LITERAL_SCALAR_STYLE = 4;

    public const FOLDED_SCALAR_STYLE = 5;

    public const ANY_ENCODING = 0;

    public const UTF8_ENCODING = 1;

    public const UTF16LE_ENCODING = 2;

    public const UTF16BE_ENCODING = 3;

    public const ANY_BREAK = 0;

    public const CR_BREAK = 1;

    public const LN_BREAK = 2;

    public const CRLN_BREAK = 3;

    public const NULL_TAG = 'tag:yaml.org,2002:null';

    public const BOOL_TAG = 'tag:yaml.org,2002:bool';

    public const STR_TAG = 'tag:yaml.org,2002:str';

    public const INT_TAG = 'tag:yaml.org,2002:int';

    public const FLOAT_TAG = 'tag:yaml.org,2002:float';

    public const TIMESTAMP_TAG = 'tag:yaml.org,2002:timestamp';

    public const SEQ_TAG = 'tag:yaml.org,2002:seq';

    public const MAP_TAG = 'tag:yaml.org,2002:map';

    public const PHP_TAG = '!php/object';

    public const MERGE_TAG = 'tag:yaml.org,2002:merge';

    public const BINARY_TAG = 'tag:yaml.org,2002:binary';

    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        return [
            'YAML_ANY_SCALAR_STYLE' => self::ANY_SCALAR_STYLE,
            'YAML_PLAIN_SCALAR_STYLE' => self::PLAIN_SCALAR_STYLE,
            'YAML_SINGLE_QUOTED_SCALAR_STYLE' => self::SINGLE_QUOTED_SCALAR_STYLE,
            'YAML_DOUBLE_QUOTED_SCALAR_STYLE' => self::DOUBLE_QUOTED_SCALAR_STYLE,
            'YAML_LITERAL_SCALAR_STYLE' => self::LITERAL_SCALAR_STYLE,
            'YAML_FOLDED_SCALAR_STYLE' => self::FOLDED_SCALAR_STYLE,
            'YAML_NULL_TAG' => self::NULL_TAG,
            'YAML_BOOL_TAG' => self::BOOL_TAG,
            'YAML_STR_TAG' => self::STR_TAG,
            'YAML_INT_TAG' => self::INT_TAG,
            'YAML_FLOAT_TAG' => self::FLOAT_TAG,
            'YAML_TIMESTAMP_TAG' => self::TIMESTAMP_TAG,
            'YAML_SEQ_TAG' => self::SEQ_TAG,
            'YAML_MAP_TAG' => self::MAP_TAG,
            'YAML_PHP_TAG' => self::PHP_TAG,
            'YAML_MERGE_TAG' => self::MERGE_TAG,
            'YAML_BINARY_TAG' => self::BINARY_TAG,
            'YAML_ANY_ENCODING' => self::ANY_ENCODING,
            'YAML_UTF8_ENCODING' => self::UTF8_ENCODING,
            'YAML_UTF16LE_ENCODING' => self::UTF16LE_ENCODING,
            'YAML_UTF16BE_ENCODING' => self::UTF16BE_ENCODING,
            'YAML_ANY_BREAK' => self::ANY_BREAK,
            'YAML_CR_BREAK' => self::CR_BREAK,
            'YAML_LN_BREAK' => self::LN_BREAK,
            'YAML_CRLN_BREAK' => self::CRLN_BREAK,
        ];
    }
}
