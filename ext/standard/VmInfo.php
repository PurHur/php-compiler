<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** VM helpers for phpversion/php_uname/php_sapi_name/version_compare/extension introspection (#3174, #3204). */
final class VmInfo
{
    /** @var list<string> */
    public const LOADED_EXTENSIONS = ['standard', 'types'];

    public static function phpversion(?string $extension = null): string|false
    {
        if (null === $extension) {
            return CompilerVersion::VERSION;
        }
        if (!self::extension_loaded($extension)) {
            return false;
        }

        return CompilerVersion::VERSION;
    }

    public static function php_sapi_name(): string
    {
        return CompilerVersion::SAPI;
    }

    public static function php_uname(string $mode = 'a'): string
    {
        self::validateUnameMode($mode);

        return \php_uname($mode);
    }

    public static function extension_loaded(string $extension): bool
    {
        $needle = strtolower($extension);
        foreach (self::LOADED_EXTENSIONS as $name) {
            if ($name === $needle) {
                return true;
            }
        }

        return false;
    }

    public static function get_loaded_extensions(bool $zendExtensions = false): HashTable
    {
        $ht = new HashTable();
        if ($zendExtensions) {
            return $ht;
        }
        foreach (self::LOADED_EXTENSIONS as $name) {
            $var = new Variable();
            $var->string($name);
            $ht->append($var);
        }

        return $ht;
    }

    public static function version_compare(string $ver1, string $ver2, ?string $operator = null): int|bool
    {
        $compare = self::phpVersionCompare($ver1, $ver2);
        if (null === $operator) {
            return $compare;
        }

        return self::applyVersionCompareOperator($compare, $operator);
    }

    public static function applyVersionCompareOperator(int $compare, string $operator): bool
    {
        $len = \strlen($operator);
        if (
            (1 === $len && '<' === $operator)
            || (2 === $len && 0 === strncmp($operator, 'lt', 2))
        ) {
            return -1 === $compare;
        }
        if (
            (2 === $len && '<=' === $operator)
            || (2 === $len && 0 === strncmp($operator, 'le', 2))
        ) {
            return 1 !== $compare;
        }
        if (
            (1 === $len && '>' === $operator)
            || (2 === $len && 0 === strncmp($operator, 'gt', 2))
        ) {
            return 1 === $compare;
        }
        if (
            (2 === $len && '>=' === $operator)
            || (2 === $len && 0 === strncmp($operator, 'ge', 2))
        ) {
            return -1 !== $compare;
        }
        if (
            (2 === $len && '==' === $operator)
            || (1 === $len && '=' === $operator)
            || (2 === $len && 0 === strncmp($operator, 'eq', 2))
        ) {
            return 0 === $compare;
        }
        if (
            (2 === $len && '!=' === $operator)
            || (2 === $len && '<>' === $operator)
            || (2 === $len && 0 === strncmp($operator, 'ne', 2))
        ) {
            return 0 !== $compare;
        }

        throw new \LogicException(
            'version_compare(): Argument #3 ($operator) must be a valid comparison operator'
        );
    }

    private static function phpVersionCompare(string $origVer1, string $origVer2): int
    {
        if ('' === $origVer1 || '' === $origVer2) {
            if ('' === $origVer1 && '' === $origVer2) {
                return 0;
            }

            return '' !== $origVer1 ? 1 : -1;
        }

        $ver1 = '#' === $origVer1[0] ? $origVer1 : self::canonicalizeVersion($origVer1);
        $ver2 = '#' === $origVer2[0] ? $origVer2 : self::canonicalizeVersion($origVer2);
        $p1 = $ver1;
        $p2 = $ver2;
        $compare = 0;
        $n1 = null;
        $n2 = null;

        while ('' !== $p1 && '' !== $p2) {
            $parts1 = explode('.', $p1, 2);
            $parts2 = explode('.', $p2, 2);
            $seg1 = $parts1[0];
            $seg2 = $parts2[0];
            $n1 = \array_key_exists(1, $parts1) ? $parts1[1] : null;
            $n2 = \array_key_exists(1, $parts2) ? $parts2[1] : null;

            if (ctype_digit($seg1) && ctype_digit($seg2)) {
                $compare = (int) $seg1 <=> (int) $seg2;
            } elseif (!ctype_digit($seg1) && !ctype_digit($seg2)) {
                $compare = self::compareSpecialVersionForms($seg1, $seg2);
            } elseif (ctype_digit($seg1)) {
                $compare = self::compareSpecialVersionForms('#N#', $seg2);
            } else {
                $compare = self::compareSpecialVersionForms($seg1, '#N#');
            }
            if (0 !== $compare) {
                break;
            }
            $p1 = null !== $n1 ? $n1 : '';
            $p2 = null !== $n2 ? $n2 : '';
        }

        if (0 === $compare) {
            if (null !== $n1) {
                if (ctype_digit($p1)) {
                    $compare = 1;
                } else {
                    $compare = self::phpVersionCompare($p1, '#N#');
                }
            } elseif (null !== $n2) {
                if (ctype_digit($p2)) {
                    $compare = -1;
                } else {
                    $compare = self::phpVersionCompare('#N#', $p2);
                }
            }
        }

        return $compare;
    }

    private static function canonicalizeVersion(string $version): string
    {
        if ('' === $version) {
            return '';
        }

        $len = \strlen($version);
        $buf = '';
        $lp = $version[0];
        $buf .= $lp;
        for ($i = 1; $i < $len; ++$i) {
            $ch = $version[$i];
            $lq = $buf[\strlen($buf) - 1];
            if ('-' === $ch || '_' === $ch || '+' === $ch) {
                if ('.' !== $lq) {
                    $buf .= '.';
                }
            } elseif (
                (!ctype_digit($lp) && ctype_digit($ch))
                || (ctype_digit($lp) && !ctype_digit($ch))
            ) {
                if ('.' !== $lq) {
                    $buf .= '.';
                }
                $buf .= $ch;
            } elseif (!ctype_alnum($ch)) {
                if ('.' !== $lq) {
                    $buf .= '.';
                }
            } else {
                $buf .= $ch;
            }
            $lp = $ch;
        }
        if ('.' === $buf[\strlen($buf) - 1]) {
            $buf = substr($buf, 0, -1);
        }

        return $buf;
    }

    private static function compareSpecialVersionForms(string $form1, string $form2): int
    {
        /** @var array<string, int> */
        static $orders = [
            'dev' => 0,
            'alpha' => 1,
            'a' => 1,
            'beta' => 2,
            'b' => 2,
            'RC' => 3,
            'rc' => 3,
            '#' => 4,
            'pl' => 5,
            'p' => 5,
        ];
        $found1 = -1;
        $found2 = -1;
        foreach ($orders as $name => $order) {
            if (0 === strncmp($form1, $name, \strlen($name))) {
                $found1 = $order;
                break;
            }
        }
        foreach ($orders as $name => $order) {
            if (0 === strncmp($form2, $name, \strlen($name))) {
                $found2 = $order;
                break;
            }
        }

        return $found1 <=> $found2;
    }

    private static function validateUnameMode(string $mode): void
    {
        if ('' === $mode || !isset($mode[0])) {
            return;
        }
        if (!\in_array($mode[0], ['a', 's', 'n', 'r', 'v', 'm'], true)) {
            throw new \LogicException(
                'php_uname(): Argument #1 ($mode) must be one of "a", "s", "n", "r", "v", or "m"'
            );
        }
    }
}
