<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * php-src internal parameter default values for ReflectionParameter (#18356).
 *
 * InternalArgInfo marks optional params with `=` but not whether reflection exposes
 * getDefaultValue() — mirror Zend via explicit tables + conservative inference
 * (ext/reflection/php_reflection.c — _reflection_parameter_get_default_value).
 */
final class BuiltinInternalDefaultValues
{
    /**
     * Optional internal params that must not report isDefaultValueAvailable (php-src).
     *
     * @var array<string, true>
     */
    private const NO_DEFAULT_AVAILABLE = [
        'array_walk::2' => true,
        'array_walk_recursive::2' => true,
    ];

    /**
     * Explicit Zend default materialization keyed by lowercase callable + param index.
     *
     * @var array<string, array<int, array{kind: string, value?: mixed}>>
     */
    private const EXPLICIT = [
        'arrayobject::__construct' => [
            0 => ['kind' => 'array'],
            1 => ['kind' => 'int', 'value' => 0],
            2 => ['kind' => 'string', 'value' => 'ArrayIterator'],
        ],
        'datetime::__construct' => [
            0 => ['kind' => 'string', 'value' => 'now'],
            1 => ['kind' => 'null'],
        ],
        'splfileobject::__construct' => [
            1 => ['kind' => 'string', 'value' => 'r'],
            2 => ['kind' => 'bool', 'value' => false],
            3 => ['kind' => 'null'],
        ],
        'htmlspecialchars' => [
            1 => ['kind' => 'int', 'value' => 11],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'bool', 'value' => true],
        ],
        'array_search' => [
            2 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src stubs — InternalArgInfo types omit nullability / non-zero sentinels (#23181)
        'substr' => [
            2 => ['kind' => 'null'],
        ],
        'json_encode' => [
            1 => ['kind' => 'int', 'value' => 0],
            2 => ['kind' => 'int', 'value' => 512],
        ],
        'json_decode' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'int', 'value' => 512],
            3 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/json/json.stub.php — depth=512, flags=0 (#23876)
        'json_validate' => [
            1 => ['kind' => 'int', 'value' => 512],
            2 => ['kind' => 'int', 'value' => 0],
        ],
        'explode' => [
            2 => ['kind' => 'int', 'value' => \PHP_INT_MAX],
        ],
        'preg_match' => [
            2 => ['kind' => 'null'],
            3 => ['kind' => 'int', 'value' => 0],
            4 => ['kind' => 'int', 'value' => 0],
        ],
        'hash' => [
            2 => ['kind' => 'bool', 'value' => false],
            3 => ['kind' => 'array'],
        ],
        // php-src ext/hash/hash.stub.php — length=0, info="", salt="" (string defaults not inferred) (#25018)
        'hash_hkdf' => [
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'string', 'value' => ''],
            4 => ['kind' => 'string', 'value' => ''],
        ],
        'openssl_encrypt' => [
            3 => ['kind' => 'int', 'value' => 0],
            4 => ['kind' => 'string', 'value' => ''],
            5 => ['kind' => 'null'],
            6 => ['kind' => 'string', 'value' => ''],
            7 => ['kind' => 'int', 'value' => 16],
        ],
        'array_slice' => [
            2 => ['kind' => 'null'],
            3 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/date/php_date.stub.php — ?int = null (InternalArgInfo int → 0) (#24845)
        'date' => [
            1 => ['kind' => 'null'],
        ],
        'gmdate' => [
            1 => ['kind' => 'null'],
        ],
        'strtotime' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/calendar/calendar.stub.php — ?int $timestamp = null (#24863)
        'unixtojd' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/standard/array.stub.php — callback=null, mode=0 (#24843)
        'array_filter' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/standard/file.stub.php — permissions=0777, context=null (#24885)
        // InternalArgInfo int → 0; untyped context= has no inferrable default.
        'mkdir' => [
            1 => ['kind' => 'int', 'value' => 0777],
            3 => ['kind' => 'null'],
        ],
        // php-src ext/standard/string.stub.php — &$count = null (InternalArgInfo int → 0) (#24886)
        'str_replace' => [
            3 => ['kind' => 'null'],
        ],
        'str_ireplace' => [
            3 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — value/path/domain = "" (#24968)
        'setcookie' => [
            1 => ['kind' => 'string', 'value' => ''],
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'string', 'value' => ''],
            4 => ['kind' => 'string', 'value' => ''],
            5 => ['kind' => 'bool', 'value' => false],
            6 => ['kind' => 'bool', 'value' => false],
        ],
        'setrawcookie' => [
            1 => ['kind' => 'string', 'value' => ''],
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'string', 'value' => ''],
            4 => ['kind' => 'string', 'value' => ''],
            5 => ['kind' => 'bool', 'value' => false],
            6 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/standard/basic_functions.stub.php — int $offset = 0 (#24896)
        // InternalArgInfo omits offset; override has no type → need explicit default.
        'unpack' => [
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/standard stubs — Reflection optional defaults cluster (#24971)
        'dirname' => [
            1 => ['kind' => 'int', 'value' => 1],
        ],
        'basename' => [
            1 => ['kind' => 'string', 'value' => ''],
        ],
        'http_build_query' => [
            1 => ['kind' => 'string', 'value' => ''],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'int', 'value' => 1], // PHP_QUERY_RFC1738
        ],
        'chunk_split' => [
            1 => ['kind' => 'int', 'value' => 76],
            2 => ['kind' => 'string', 'value' => "\r\n"],
        ],
        'umask' => [
            0 => ['kind' => 'null'],
        ],
        'touch' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
        ],
        'get_html_translation_table' => [
            0 => ['kind' => 'int', 'value' => 0],
            1 => ['kind' => 'int', 'value' => 11], // ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401
            2 => ['kind' => 'string', 'value' => 'UTF-8'],
        ],
        'version_compare' => [
            2 => ['kind' => 'null'],
        ],
        'getimagesize' => [
            1 => ['kind' => 'null'],
        ],
        'session_set_cookie_params' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — ?array = null (InternalArgInfo array → []) (#25069)
        'stream_context_create' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/file.stub.php — ?int $length = null (InternalArgInfo int → 0) (#24846)
        'fwrite' => [
            2 => ['kind' => 'null'],
        ],
        'fgets' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/file.stub.php — ?int length=null; separator/enclosure/escape strings (#24826)
        'fgetcsv' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'string', 'value' => ','],
            3 => ['kind' => 'string', 'value' => '"'],
            4 => ['kind' => 'string', 'value' => '\\'],
        ],
    ];

    public static function isAvailable(
        string $callableLc,
        int $index,
        ?array $info,
        bool $isVariadic,
    ): bool {
        if (null === $info || !$info['isOptional'] || $isVariadic) {
            return false;
        }
        $key = $callableLc.'::'.$index;
        if (isset(self::NO_DEFAULT_AVAILABLE[$key])) {
            return false;
        }
        if (isset(self::EXPLICIT[$callableLc][$index])) {
            return true;
        }

        return null !== self::inferSpec($callableLc, $index, $info);
    }

    public static function materialize(
        Variable $dest,
        string $callableLc,
        int $index,
        ?array $info,
    ): bool {
        if (null === $info) {
            return false;
        }
        $spec = self::EXPLICIT[$callableLc][$index] ?? self::inferSpec($callableLc, $index, $info);
        if (null === $spec) {
            return false;
        }
        self::writeSpec($dest, $spec);

        return true;
    }

    /**
     * @return array{kind: string, value?: mixed}|null
     */
    private static function inferSpec(string $callableLc, int $index, array $info): ?array
    {
        $type = strtolower(trim($info['type']));
        $name = strtolower($info['name']);

        if (str_contains($callableLc, '::') && str_ends_with($callableLc, '::__construct')) {
            if ('iterator_class' === $name) {
                return ['kind' => 'string', 'value' => 'ArrayIterator'];
            }
            if ('datetime' === $name) {
                return ['kind' => 'string', 'value' => 'now'];
            }
            if ('mode' === $name) {
                return ['kind' => 'string', 'value' => 'r'];
            }
            if ('flags' === $name && ('int' === $type || '' === $type)) {
                return ['kind' => 'int', 'value' => 0];
            }
            if ('array' === $type || self::isArrayLikeParamName($name)) {
                return ['kind' => 'array'];
            }
            if (self::typeIsNullable($type)) {
                return ['kind' => 'null'];
            }
        }

        if ('bool' === $type) {
            return ['kind' => 'bool', 'value' => false];
        }
        if ('int' === $type) {
            return ['kind' => 'int', 'value' => 0];
        }
        if ('float' === $type || 'double' === $type) {
            return ['kind' => 'float', 'value' => 0.0];
        }
        if ('array' === $type || self::isArrayLikeParamName($name)) {
            return ['kind' => 'array'];
        }
        if ('string' === $type && 'characters' === $name) {
            return ['kind' => 'string', 'value' => " \t\n\r\0\x0B"];
        }
        if (self::typeIsNullable($type)) {
            return ['kind' => 'null'];
        }

        return null;
    }

    private static function typeIsNullable(string $type): bool
    {
        if ('' === $type) {
            return false;
        }
        if (str_starts_with($type, '?')) {
            return true;
        }
        if (str_contains($type, '|')) {
            foreach (explode('|', $type) as $member) {
                if ('null' === strtolower(trim($member))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isArrayLikeParamName(string $name): bool
    {
        return \in_array($name, [
            'input', 'array', 'arr', 'arr1', 'stack', 'haystack', 'values', 'array_arg',
        ], true);
    }

    /**
     * @param array{kind: string, value?: mixed} $spec
     */
    private static function writeSpec(Variable $dest, array $spec): void
    {
        switch ($spec['kind']) {
            case 'null':
                $dest->null();
                break;
            case 'bool':
                $dest->bool((bool) ($spec['value'] ?? false));
                break;
            case 'int':
                $dest->int((int) ($spec['value'] ?? 0));
                break;
            case 'float':
                $dest->float((float) ($spec['value'] ?? 0.0));
                break;
            case 'string':
                $dest->string((string) ($spec['value'] ?? ''));
                break;
            case 'array':
                $dest->newArray();
                break;
            default:
                throw new \LogicException('Unknown internal default kind: '.$spec['kind']);
        }
    }
}
