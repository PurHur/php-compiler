<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for phpversion/php_uname/php_sapi_name/version_compare/extension introspection (#3174, #3204, #7190, #8171).
 * and phpinfo/phpcredits/zend_version runtime reports (#3359, #5304).
 *
 * php-src: ext/standard/info.c
 */
final class VmInfo
{
    /** php-src PHP_INFO_* (ext/standard/info.c). */
    public const INFO_GENERAL = 1;

    public const INFO_CREDITS = 2;

    public const INFO_CONFIGURATION = 4;

    public const INFO_MODULES = 8;

    public const INFO_ENVIRONMENT = 16;

    public const INFO_VARIABLES = 32;

    public const INFO_LICENSE = 64;

    public const INFO_ALL = -1;

    /** php-src PHP_CREDITS_* (ext/standard/info.c). */
    public const CREDITS_GROUP = 1;

    public const CREDITS_GENERAL = 2;

    public const CREDITS_SAPI = 4;

    public const CREDITS_MODULES = 8;

    public const CREDITS_DOCS = 16;

    public const CREDITS_FULLPAGE = 32;

    public const CREDITS_QA = 64;

    public const CREDITS_ALL = -1;

    /** Zend engine version label for zend_version() (php-src Zend/zend.c ZEND_VERSION shape). */
    public const ZEND_VERSION = '4.4.0';

    /** php-src maps the Zend engine module to extension name Core (ext/standard/info.c). */
    public static function isEngineExtensionName(string $extension): bool
    {
        return 'core' === strtolower($extension);
    }

    public static function phpversion(?string $extension = null): string|false
    {
        if (null === $extension || self::isEngineExtensionName($extension)) {
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
        return VmUnameNative::php_uname($mode);
    }

    public static function extension_loaded(string $extension): bool
    {
        return ModuleRegistry::extensionLoaded($extension);
    }

    public static function get_loaded_extensions(bool $zendExtensions = false): HashTable
    {
        $ht = new HashTable();
        if ($zendExtensions) {
            return $ht;
        }
        foreach (ModuleRegistry::getLoadedExtensions() as $name) {
            $var = new Variable();
            $var->string($name);
            $ht->append($var);
        }

        return $ht;
    }

    /**
     * @return HashTable|false
     */
    public static function get_extension_funcs(string $extension): HashTable|false
    {
        $funcs = ModuleRegistry::getExtensionFunctions($extension);
        if (null === $funcs) {
            return false;
        }
        $ht = new HashTable();
        foreach ($funcs as $name) {
            $var = new Variable();
            $var->string($name);
            $ht->append($var);
        }

        return $ht;
    }

    public static function zend_version(): string
    {
        return self::ZEND_VERSION;
    }

    /**
     * Coerce phpinfo() $flags from int|InfoView|null (php-src-strict, #7285).
     */
    public static function resolvePhpinfoFlagsArg(Variable $var): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return self::INFO_ALL;
        }
        $fromEnum = self::tryInfoViewInt($resolved);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            throw new \TypeError(sprintf(
                'phpinfo(): Argument #1 ($flags) must be of type InfoView|int|null, %s given',
                EnumCaseSupport::typeNameForVariable($resolved)
            ));
        }
        if (Variable::TYPE_INTEGER !== $resolved->type && Variable::TYPE_FLOAT !== $resolved->type) {
            throw new \TypeError(sprintf(
                'phpinfo(): Argument #1 ($flags) must be of type InfoView|int|null, %s given',
                EnumCaseSupport::typeNameForVariable($resolved)
            ));
        }

        return (int) $resolved->toInt();
    }

    public static function tryInfoViewInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isInfoViewEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('InfoView case missing backing value');
        }

        return $entry->backingValue->resolveIndirect()->toInt();
    }

    private static function isInfoViewEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'InfoView');
    }

    public static function phpinfo(int $flags = self::INFO_ALL): bool
    {
        OutputBuffer::append('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">');
        OutputBuffer::append('<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />');
        OutputBuffer::append('<title>phpinfo()</title><style type="text/css">.h{background-color:#9999cc;font-weight:bold;color:#000000;}');
        OutputBuffer::append('.v{background-color:#cccccc;color:#000000;}</style></head><body><div class="center">');
        if (self::infoFlagSelected($flags, self::INFO_GENERAL)) {
            self::printInfoGeneralSection();
        }
        if (self::infoFlagSelected($flags, self::INFO_MODULES)) {
            self::printInfoModulesSection();
        }
        if (self::infoFlagSelected($flags, self::INFO_CONFIGURATION)) {
            self::printInfoConfigurationSection();
        }
        if (self::infoFlagSelected($flags, self::INFO_LICENSE)) {
            self::printInfoLicenseSection();
        }
        if (self::infoFlagSelected($flags, self::INFO_CREDITS)) {
            self::printCreditsSection(self::CREDITS_GENERAL);
        }
        OutputBuffer::append('</div></body></html>');

        return true;
    }

    public static function phpcredits(int $flags = self::CREDITS_ALL): void
    {
        self::printCreditsSection($flags);
    }

    public static function version_compare(string $ver1, string $ver2, ?string $operator = null): int|bool
    {
        $compare = self::phpVersionCompare($ver1, $ver2);
        if (null === $operator) {
            return $compare;
        }

        return self::applyVersionCompareOperator($compare, $operator);
    }

    public const VERSION_COMPARE_OPERATOR_ERROR =
        'version_compare(): Argument #3 ($operator) must be a valid comparison operator';

    public static function isValidVersionCompareOperator(string $operator): bool
    {
        return null !== self::evaluateVersionCompareOperator(0, $operator);
    }

    /**
     * @return bool|null null when $operator is not a valid comparison operator (php-src versioning.c)
     */
    public static function evaluateVersionCompareOperator(int $compare, string $operator): ?bool
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

        return null;
    }

    public static function applyVersionCompareOperator(int $compare, string $operator): bool
    {
        $result = self::evaluateVersionCompareOperator($compare, $operator);
        if (null === $result) {
            throw new \ValueError(self::VERSION_COMPARE_OPERATOR_ERROR);
        }

        return $result;
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
                (self::isVersionNonDigitChar($lp) && self::isVersionDigitChar($ch))
                || (self::isVersionDigitChar($lp) && self::isVersionNonDigitChar($ch))
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

    /** php-src versioning.c isdig(): digit excluding '.' */
    private static function isVersionDigitChar(string $ch): bool
    {
        return ctype_digit($ch);
    }

    /** php-src versioning.c isndig(): non-digit excluding '.' */
    private static function isVersionNonDigitChar(string $ch): bool
    {
        return !ctype_digit($ch) && '.' !== $ch;
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

    private static function infoFlagSelected(int $flags, int $section): bool
    {
        if (self::INFO_ALL === $flags) {
            return true;
        }

        return 0 !== ($flags & $section);
    }

    private static function creditsFlagSelected(int $flags, int $section): bool
    {
        if (self::CREDITS_ALL === $flags) {
            return true;
        }

        return 0 !== ($flags & $section);
    }

    private static function printInfoGeneralSection(): void
    {
        $version = CompilerVersion::VERSION;
        $sapi = self::php_sapi_name();
        $system = self::php_uname('s');
        $host = self::php_uname('n');
        $release = self::php_uname('r');
        $versionStr = self::php_uname('v');
        $machine = self::php_uname('m');
        OutputBuffer::append('<table><tr class="h"><td colspan="2"><h1>PHP Version '.$version.'</h1></td></tr>');
        OutputBuffer::append('<tr><td class="e">System </td><td class="v">'.$system.' '.$host.' '.$release.' '.$versionStr.' '.$machine.' </td></tr>');
        OutputBuffer::append('<tr><td class="e">Build System </td><td class="v">'.$system.' '.$machine.' </td></tr>');
        OutputBuffer::append('<tr><td class="e">Server API </td><td class="v">'.$sapi.' </td></tr>');
        OutputBuffer::append('<tr><td class="e">PHP Version </td><td class="v">'.$version.' </td></tr>');
        OutputBuffer::append('<tr><td class="e">Zend Engine Version </td><td class="v">'.self::ZEND_VERSION.' </td></tr>');
        OutputBuffer::append('</table><br />');
    }

    private static function printInfoModulesSection(): void
    {
        $extensions = ModuleRegistry::getLoadedExtensions();
        sort($extensions, SORT_STRING);
        OutputBuffer::append('<table><tr class="h"><td colspan="2"><h2>PHP Modules</h2></td></tr>');
        OutputBuffer::append('<tr><td class="e">Module Name </td><td class="v">Enabled </td></tr>');
        foreach ($extensions as $name) {
            OutputBuffer::append('<tr><td class="e">'.$name.' </td><td class="v">enabled </td></tr>');
        }
        OutputBuffer::append('</table><br />');
    }

    private static function printInfoConfigurationSection(): void
    {
        OutputBuffer::append('<table><tr class="h"><td colspan="2"><h2>Configuration</h2></td></tr>');
        OutputBuffer::append('<tr><td class="e">Compiler </td><td class="v">PurHur/php-compiler </td></tr>');
        OutputBuffer::append('</table><br />');
    }

    private static function printInfoLicenseSection(): void
    {
        OutputBuffer::append('<table><tr class="h"><td colspan="2"><h2>PHP License</h2></td></tr>');
        OutputBuffer::append('<tr><td class="v" colspan="2">This program is free software; you can redistribute it and/or modify it under the terms of the PHP License.</td></tr>');
        OutputBuffer::append('</table><br />');
    }

    private static function printCreditsSection(int $flags): void
    {
        if (!self::creditsFlagSelected($flags, self::CREDITS_GENERAL)) {
            return;
        }
        OutputBuffer::append('<table><tr class="h"><td colspan="2"><h2>PHP Credits</h2></td></tr>');
        OutputBuffer::append('<tr><td class="v" colspan="2">PurHur/php-compiler — PHP-in-PHP compiler runtime</td></tr>');
        OutputBuffer::append('</table><br />');
    }
}
