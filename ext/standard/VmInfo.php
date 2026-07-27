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

    public const CREDITS_WEB = 128;

    public const CREDITS_ALL = -1;

    /** phpinfo(INFO_CREDITS) → php_print_credits(CREDITS_ALL & ~FULLPAGE) (ext/standard/info.c, #16347). */
    private const PHPINFO_CREDITS_FLAGS =
        self::CREDITS_GROUP
        | self::CREDITS_GENERAL
        | self::CREDITS_SAPI
        | self::CREDITS_MODULES
        | self::CREDITS_DOCS
        | self::CREDITS_QA
        | self::CREDITS_WEB;

    /** php-src maps the Zend engine module to extension name Core (ext/standard/info.c). */
    public static function isEngineExtensionName(string $extension): bool
    {
        return 'core' === strtolower($extension);
    }

    /** Built-in/static extensions report PHP runtime version from phpversion() (ext/standard/info.c). */
    public static function isBundledExtensionName(string $extension): bool
    {
        return ModuleRegistry::isBundledExtension($extension);
    }

    public static function phpversion(?string $extension = null): string|false
    {
        $runtimeVersion = CompilerVersion::reportedPhpVersion();
        if (null === $extension || self::isEngineExtensionName($extension)) {
            return $runtimeVersion;
        }
        if (!self::extension_loaded($extension)) {
            return false;
        }

        return ModuleRegistry::getExtensionVersion($extension) ?? $runtimeVersion;
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
            foreach (ModuleRegistry::getLoadedZendExtensions() as $name) {
                $var = new Variable();
                $var->string($name);
                $ht->append($var);
            }

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
        return CompilerVersion::zendVersion();
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
        OutputBuffer::append(self::renderPhpinfo($flags));

        return true;
    }

    /** php-src sapi_module.phpinfo_as_text — CLI emits plain-text rows (#16489). */
    public static function phpinfoUsesTextFormat(): bool
    {
        return 'cli' === strtolower(self::php_sapi_name());
    }

    /** Shared phpinfo() output for VM + JIT ({@see PhpinfoJitHelper}, issue #9256, #16489). */
    public static function renderPhpinfo(int $flags): string
    {
        return self::phpinfoUsesTextFormat()
            ? self::renderPhpinfoText($flags)
            : self::renderPhpinfoHtml($flags);
    }

    public static function phpcredits(int $flags = self::CREDITS_ALL): void
    {
        OutputBuffer::append(self::renderPhpcreditsText($flags));
    }

    /** php-src php_print_info() text layout for CLI SAPI (ext/standard/info.c, #16489). */
    public static function renderPhpinfoText(int $flags): string
    {
        $text = "phpinfo()\n";
        if (self::infoFlagSelected($flags, self::INFO_GENERAL)) {
            $text .= self::generalSectionText();
        }
        if (self::infoFlagSelected($flags, self::INFO_MODULES)) {
            $text .= self::modulesSectionText();
        }
        if (self::infoFlagSelected($flags, self::INFO_CONFIGURATION)) {
            $text .= self::configurationSectionText();
        }
        if (self::infoFlagSelected($flags, self::INFO_LICENSE)) {
            $text .= self::licenseSectionText();
        }
        if (self::infoFlagSelected($flags, self::INFO_CREDITS)) {
            $text .= self::phpinfoCreditsIntroText();
            $text .= self::creditsSectionText(self::PHPINFO_CREDITS_FLAGS, true);
        }

        return $text;
    }

    /** Shared phpinfo() HTML for VM + JIT ({@see PhpinfoJitHelper}, issue #9256). */
    public static function renderPhpinfoHtml(int $flags): string
    {
        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">';
        $html .= '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
        $html .= '<title>phpinfo()</title><style type="text/css">.h{background-color:#9999cc;font-weight:bold;color:#000000;}';
        $html .= '.v{background-color:#cccccc;color:#000000;}</style></head><body><div class="center">';
        if (self::infoFlagSelected($flags, self::INFO_GENERAL)) {
            $html .= self::generalSectionHtml();
        }
        if (self::infoFlagSelected($flags, self::INFO_MODULES)) {
            $html .= self::modulesSectionHtml();
        }
        if (self::infoFlagSelected($flags, self::INFO_CONFIGURATION)) {
            $html .= self::configurationSectionHtml();
        }
        if (self::infoFlagSelected($flags, self::INFO_LICENSE)) {
            $html .= self::licenseSectionHtml();
        }
        if (self::infoFlagSelected($flags, self::INFO_CREDITS)) {
            $html .= self::creditsSectionHtml(self::PHPINFO_CREDITS_FLAGS, true);
        }
        $html .= '</div></body></html>';

        return $html;
    }

    /** php-src phpcredits() plain-text credits (ext/standard/credits.c, sapi_module.phpinfo_as_text). */
    public static function renderPhpcreditsText(int $flags): string
    {
        // CREDITS_ALL → full credits_modules[] table; CREDITS_MODULES alone → loaded extensions (#16338).
        $fullModuleTable = self::isCreditsAll($flags);

        return 'PHP Credits'."\n".self::creditsSectionText($flags, $fullModuleTable);
    }

    /** @deprecated Alias for {@see renderPhpcreditsText()}. */
    public static function renderPhpcreditsHtml(int $flags): string
    {
        return self::renderPhpcreditsText($flags);
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

        // php-src versioning.c: isdigit(*p) on the first character of the
        // remaining segment only — not ctype_digit on the whole remainder
        // (e.g. "0.dev" after "8.4" vs "8.4.0-dev"). Full-string digit
        // checks flip X.Y vs X.Y.Z-dev (#23508).
        if (0 === $compare) {
            if (null !== $n1) {
                if ('' !== $p1 && self::isVersionDigitChar($p1[0])) {
                    $compare = 1;
                } else {
                    $compare = self::phpVersionCompare($p1, '#N#');
                }
            } elseif (null !== $n2) {
                if ('' !== $p2 && self::isVersionDigitChar($p2[0])) {
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
        if (self::isCreditsAll($flags)) {
            return true;
        }

        return 0 !== ($flags & $section);
    }

    /** CREDITS_ALL is -1; userland constant may surface as uint32 max (#16367). */
    private static function isCreditsAll(int $flags): bool
    {
        return self::CREDITS_ALL === $flags || 0xFFFFFFFF === ($flags & 0xFFFFFFFF);
    }

    private static function generalSectionHtml(): string
    {
        $version = CompilerVersion::VERSION;
        $sapi = self::php_sapi_name();
        $system = self::php_uname('s');
        $host = self::php_uname('n');
        $release = self::php_uname('r');
        $versionStr = self::php_uname('v');
        $machine = self::php_uname('m');
        $html = '<table><tr class="h"><td colspan="2"><h1>PHP Version '.$version.'</h1></td></tr>';
        $html .= '<tr><td class="e">System </td><td class="v">'.$system.' '.$host.' '.$release.' '.$versionStr.' '.$machine.' </td></tr>';
        $html .= '<tr><td class="e">Build Date </td><td class="v">'.CompilerVersion::BUILD_DATE.' </td></tr>';
        $html .= '<tr><td class="e">Build System </td><td class="v">'.$system.' '.$machine.' </td></tr>';
        $buildProvider = VmIniIntrospection::phpinfoGeneralRow('Build Provider');
        if ('' !== $buildProvider) {
            $html .= '<tr><td class="e">Build Provider </td><td class="v">'.$buildProvider.' </td></tr>';
        }
        $html .= '<tr><td class="e">Server API </td><td class="v">'.$sapi.' </td></tr>';
        foreach (self::PHPINFO_GENERAL_EXTRA_ROWS as $label) {
            $value = VmIniIntrospection::phpinfoGeneralRow($label);
            if ('Additional .ini files parsed' === $label) {
                $value = self::formatPhpinfoAdditionalIniFilesHtml($value);
            }
            $html .= '<tr><td class="e">'.$label.' </td><td class="v">'.$value.' </td></tr>';
        }
        $html .= self::generalSectionRuntimeTailHtml();
        $html .= '<tr><td class="e">PHP Version </td><td class="v">'.$version.' </td></tr>';
        $html .= '<tr><td class="e">Zend Engine Version </td><td class="v">'.CompilerVersion::zendVersion().' </td></tr>';
        $html .= '</table><br />';

        return $html;
    }

    private static function phpinfoRowText(string $label, string $value): string
    {
        return $label.' => '.$value."\n";
    }

    private static function phpinfoCreditsIntroText(): string
    {
        return "\n\n ".str_repeat('_', 71)."\n\nPHP Credits\n";
    }

    /** php-src sapi_module.name long label in phpinfo text mode (main/SAPI.c, #14283). */
    private static function phpinfoServerApiLabel(): string
    {
        return match (strtolower(self::php_sapi_name())) {
            'cli' => 'Command Line Interface',
            'cli-server' => 'Development Server',
            'cgi-fcgi', 'cgi' => 'CGI/FastCGI',
            default => self::php_sapi_name(),
        };
    }

    private static function generalSectionText(): string
    {
        $version = CompilerVersion::reportedPhpVersion();
        $system = self::php_uname('s');
        $host = self::php_uname('n');
        $release = self::php_uname('r');
        $versionStr = self::php_uname('v');
        $machine = self::php_uname('m');
        $buildDate = VmIniIntrospection::phpinfoGeneralRow('Build Date', CompilerVersion::BUILD_DATE);
        $text = self::phpinfoRowText('PHP Version', $version)."\n";
        $text .= self::phpinfoRowText('System', trim($system.' '.$host.' '.$release.' '.$versionStr.' '.$machine));
        $text .= self::phpinfoRowText('Build Date', $buildDate);
        $text .= self::phpinfoRowText('Build System', $system);
        $buildProvider = VmIniIntrospection::phpinfoGeneralRow('Build Provider');
        if ('' !== $buildProvider) {
            $text .= self::phpinfoRowText('Build Provider', $buildProvider);
        }
        $text .= self::phpinfoRowText('Server API', self::phpinfoServerApiLabel());
        foreach (self::PHPINFO_GENERAL_EXTRA_ROWS as $label) {
            $value = VmIniIntrospection::phpinfoGeneralRow($label);
            if ('Additional .ini files parsed' === $label) {
                $value = self::formatPhpinfoAdditionalIniFilesText($value);
            }
            $text .= self::phpinfoRowText($label, $value);
        }
        $text .= self::generalSectionRuntimeTailText();

        return $text;
    }

    private static function formatPhpinfoAdditionalIniFilesText(string $value): string
    {
        if ('(none)' === $value || '' === $value) {
            return $value;
        }
        $parts = preg_split('/,\s*\n?/', $value) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => '' !== $part));
        if ([] === $parts) {
            return $value;
        }

        return implode(",\n", $parts);
    }

    private static function modulesSectionText(): string
    {
        $extensions = ModuleRegistry::getLoadedExtensions();
        sort($extensions, SORT_STRING);
        $text = "\nPHP Modules\n\n";
        $text .= self::phpinfoRowText('Module Name', 'Enabled');
        foreach ($extensions as $name) {
            $text .= self::phpinfoRowText($name, 'enabled');
        }
        $text .= "\n";

        return $text;
    }

    private static function configurationSectionText(): string
    {
        $text = "\nConfiguration\n\n";
        $text .= self::phpinfoRowText('Compiler', 'PurHur/php-compiler');
        $text .= "\n";

        return $text;
    }

    private static function licenseSectionText(): string
    {
        $text = "\nPHP License\n\n";
        $text .= "This program is free software; you can redistribute it and/or modify it under the terms of the PHP License.\n\n";

        return $text;
    }

    /** php-src phpinfo(INFO_GENERAL) rows after Thread Safety (ext/standard/info.c, #14283). */
    private const PHPINFO_GENERAL_EXTRA_ROWS = [
        'Virtual Directory Support',
        'Configuration File (php.ini) Path',
        'Loaded Configuration File',
        'Scan this dir for additional .ini files',
        'Additional .ini files parsed',
        'PHP API',
        'PHP Extension',
        'Zend Extension',
        'Zend Extension Build',
        'PHP Extension Build',
        'Debug Build',
        'Thread Safety',
    ];

    /** php-src phpinfo(INFO_GENERAL) runtime rows before stream registry tail (#16551). */
    private const PHPINFO_GENERAL_RUNTIME_ROWS = [
        'Zend Signal Handling',
        'Zend Memory Manager',
        'Zend Multibyte Support',
        'Zend Max Execution Timers',
        'IPv6 Support',
        'DTrace Support',
    ];

    /** @var array<string, string> */
    private const PHPINFO_GENERAL_RUNTIME_DEFAULTS = [
        'Zend Signal Handling' => 'enabled',
        'Zend Memory Manager' => 'enabled',
        'Zend Multibyte Support' => 'disabled',
        'Zend Max Execution Timers' => 'disabled',
        'IPv6 Support' => 'enabled',
        'DTrace Support' => 'available, disabled',
    ];

    private static function generalSectionRuntimeTailText(): string
    {
        $text = '';
        foreach (self::PHPINFO_GENERAL_RUNTIME_ROWS as $label) {
            $text .= self::phpinfoRowText($label, self::phpinfoGeneralRuntimeRowValue($label));
        }
        $text .= self::phpinfoRowText('Registered PHP Streams', self::phpinfoRegisteredStreamsValue());
        $text .= self::phpinfoRowText('Registered Stream Socket Transports', self::phpinfoRegisteredTransportsValue());
        $text .= self::phpinfoRowText('Registered Stream Filters', self::phpinfoRegisteredStreamFiltersValue());
        $text .= self::generalSectionCreditFooterText();

        return $text;
    }

    private static function generalSectionRuntimeTailHtml(): string
    {
        $html = '';
        foreach (self::PHPINFO_GENERAL_RUNTIME_ROWS as $label) {
            $value = self::phpinfoGeneralRuntimeRowValue($label);
            $html .= '<tr><td class="e">'.$label.' </td><td class="v">'.$value.' </td></tr>';
        }
        $html .= '<tr><td class="e">Registered PHP Streams </td><td class="v">'.self::phpinfoRegisteredStreamsValue().' </td></tr>';
        $html .= '<tr><td class="e">Registered Stream Socket Transports </td><td class="v">'.self::phpinfoRegisteredTransportsValue().' </td></tr>';
        $html .= '<tr><td class="e">Registered Stream Filters </td><td class="v">'.self::phpinfoRegisteredStreamFiltersValue().' </td></tr>';

        return $html;
    }

    private static function phpinfoGeneralRuntimeRowValue(string $label): string
    {
        if ('Zend Multibyte Support' === $label) {
            $mirrored = VmIniIntrospection::phpinfoGeneralRow($label, '');
            if ('' !== $mirrored) {
                return $mirrored;
            }

            return ModuleRegistry::extensionLoaded('mbstring') ? 'provided by mbstring' : 'disabled';
        }

        return VmIniIntrospection::phpinfoGeneralRow(
            $label,
            self::PHPINFO_GENERAL_RUNTIME_DEFAULTS[$label] ?? ''
        );
    }

    private static function phpinfoRegisteredStreamsValue(): string
    {
        $mirrored = VmIniIntrospection::phpinfoGeneralRow('Registered PHP Streams', '');
        if ('' !== $mirrored) {
            return $mirrored;
        }

        return \implode(', ', VmStreamWrapperRegistry::getWrappers());
    }

    private static function phpinfoRegisteredTransportsValue(): string
    {
        $mirrored = VmIniIntrospection::phpinfoGeneralRow('Registered Stream Socket Transports', '');
        if ('' !== $mirrored) {
            return $mirrored;
        }

        return \implode(', ', VmStreamTransports::getRegistrationOrderTransports());
    }

    private static function phpinfoRegisteredStreamFiltersValue(): string
    {
        $mirrored = VmIniIntrospection::phpinfoGeneralRow('Registered Stream Filters', '');
        if ('' !== $mirrored) {
            return $mirrored;
        }

        return \implode(', ', VmStreamFilters::allFilterNames());
    }

    private static function generalSectionCreditFooterText(): string
    {
        $footer = VmIniIntrospection::phpinfoCreditFooter();
        if ('' !== $footer) {
            return "\n".$footer."\n";
        }
        $zendVer = CompilerVersion::zendVersion();

        return "\nThis program makes use of the Zend Scripting Language Engine:\n"
            .'Zend Engine v'.$zendVer.', Copyright (c) Zend Technologies'."\n";
    }

    private static function formatPhpinfoAdditionalIniFilesHtml(string $value): string
    {
        if ('(none)' === $value || '' === $value) {
            return $value;
        }
        $parts = preg_split('/,\s*\n?/', $value) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => '' !== $part));
        if ([] === $parts) {
            return $value;
        }

        return implode('<br />', $parts);
    }

    private static function modulesSectionHtml(): string
    {
        $extensions = ModuleRegistry::getLoadedExtensions();
        sort($extensions, SORT_STRING);
        $html = '<table><tr class="h"><td colspan="2"><h2>PHP Modules</h2></td></tr>';
        $html .= '<tr><td class="e">Module Name </td><td class="v">Enabled </td></tr>';
        foreach ($extensions as $name) {
            $html .= '<tr><td class="e">'.$name.' </td><td class="v">enabled </td></tr>';
        }
        $html .= '</table><br />';

        return $html;
    }

    private static function configurationSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>Configuration</h2></td></tr>';
        $html .= '<tr><td class="e">Compiler </td><td class="v">PurHur/php-compiler </td></tr>';
        $html .= '</table><br />';

        return $html;
    }

    private static function licenseSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>PHP License</h2></td></tr>';
        $html .= '<tr><td class="v" colspan="2">This program is free software; you can redistribute it and/or modify it under the terms of the PHP License.</td></tr>';
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsSectionHtml(int $flags, bool $fullModuleCreditsTable = false): string
    {
        $sections = '';
        if (self::creditsFlagSelected($flags, self::CREDITS_GROUP)) {
            $sections .= self::creditsGroupSectionHtml();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_GENERAL)) {
            $sections .= self::creditsGeneralSectionHtml();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_SAPI)) {
            $sections .= self::creditsSapiSectionHtml();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_MODULES)) {
            $sections .= self::creditsModulesSectionHtml($fullModuleCreditsTable);
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_DOCS)) {
            $sections .= self::creditsDocsSectionHtml();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_QA)) {
            $sections .= self::creditsQaSectionHtml();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_WEB)) {
            $sections .= self::creditsWebSectionHtml();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_SAPI)) {
            $sections .= self::creditsDebianSectionHtml();
        }
        if ('' === $sections) {
            return '';
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_FULLPAGE)) {
            return self::creditsFullPageWrap($sections);
        }

        return $sections;
    }

    /** php-src php_print_credits() text layout (ext/standard/info.c php_info_print_table_*). */
    private static function creditsSectionText(int $flags, bool $fullModuleCreditsTable = false): string
    {
        $sections = '';
        if (self::creditsFlagSelected($flags, self::CREDITS_GROUP)) {
            $sections .= self::creditsGroupSectionText();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_GENERAL)) {
            $sections .= self::creditsGeneralSectionText();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_SAPI)) {
            $sections .= self::creditsSapiSectionText();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_MODULES)) {
            $sections .= self::creditsModulesSectionText($fullModuleCreditsTable);
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_DOCS)) {
            $sections .= self::creditsDocsSectionText();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_QA)) {
            $sections .= self::creditsQaSectionText();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_WEB)) {
            $sections .= self::creditsWebSectionText();
        }
        if (self::creditsFlagSelected($flags, self::CREDITS_SAPI)) {
            $sections .= self::creditsDebianSectionText();
        }

        return $sections;
    }

    /** @return array<string, string> */
    private static function creditsModuleAuthorRows(bool $fullModuleCreditsTable): array
    {
        return $fullModuleCreditsTable
            ? VmCreditsData::creditsModuleAuthorsForPhpinfo()
            : VmCreditsData::creditsModuleAuthorsForLoadedExtensions();
    }

    private static function creditsColspanHeaderText(string $header): string
    {
        $spaces = 74 - \strlen($header);
        if ($spaces < 0) {
            $spaces = 0;
        }
        $left = intdiv($spaces, 2);
        $right = $spaces - $left;

        return str_repeat(' ', $left).$header.str_repeat(' ', $right)."\n";
    }

    /** @param non-empty-list<string> $columns */
    private static function creditsTableHeaderText(array $columns): string
    {
        return implode(' => ', $columns)."\n";
    }

    /** @param non-empty-list<string> $columns */
    private static function creditsTableRowText(array $columns): string
    {
        return implode(' => ', $columns)."\n";
    }

    private static function creditsGroupSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>PHP Group</h2></td></tr>';
        $html .= '<tr><td class="v" colspan="2">'.VmCreditsData::PHP_GROUP.'</td></tr>';
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsGeneralSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>PHP Credits</h2></td></tr>';
        $html .= '<tr><td class="v" colspan="2">Language Design &amp; Concept<br />';
        $html .= 'Andi Gutmans, Rasmus Lerdorf, Zeev Suraski, Marcus Boerger</td></tr>';
        $html .= '</table><br />';
        $html .= '<table><tr class="h"><td colspan="2"><h2>PHP Authors</h2></td></tr>';
        $html .= '<tr><td class="e">Contribution </td><td class="v">Authors </td></tr>';
        foreach (VmCreditsData::PHP_AUTHORS as $contribution => $authors) {
            $html .= '<tr><td class="e">'.$contribution.' </td><td class="v">'.$authors.' </td></tr>';
        }
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsQaSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>PHP Quality Assurance Team</h2></td></tr>';
        $html .= '<tr><td class="v" colspan="2">'.VmCreditsData::QA_TEAM.'</td></tr>';
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsSapiSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>SAPI Modules</h2></td></tr>';
        $html .= '<tr><td class="e">Contribution </td><td class="v">Authors </td></tr>';
        foreach (VmCreditsData::SAPI_MODULES as $contribution => $authors) {
            $html .= '<tr><td class="e">'.$contribution.' </td><td class="v">'.$authors.' </td></tr>';
        }
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsGroupSectionText(): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('PHP Group');
        $text .= VmCreditsData::PHP_GROUP."\n";

        return $text;
    }

    private static function creditsGeneralSectionText(): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('Language Design & Concept');
        $text .= "Andi Gutmans, Rasmus Lerdorf, Zeev Suraski, Marcus Boerger\n";
        $text .= "\n";
        $text .= self::creditsColspanHeaderText('PHP Authors');
        $text .= self::creditsTableHeaderText(['Contribution', 'Authors']);
        foreach (VmCreditsData::PHP_AUTHORS as $contribution => $authors) {
            $text .= self::creditsTableRowText([$contribution, $authors]);
        }

        return $text;
    }

    private static function creditsQaSectionText(): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('PHP Quality Assurance Team');
        $text .= VmCreditsData::QA_TEAM."\n";

        return $text;
    }

    private static function creditsSapiSectionText(): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('SAPI Modules');
        $text .= self::creditsTableHeaderText(['Contribution', 'Authors']);
        foreach (VmCreditsData::SAPI_MODULES as $contribution => $authors) {
            $text .= self::creditsTableRowText([$contribution, $authors]);
        }

        return $text;
    }

    private static function creditsModulesSectionText(bool $fullModuleCreditsTable): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('Module Authors');
        $text .= self::creditsTableHeaderText(['Module', 'Authors']);
        foreach (self::creditsModuleAuthorRows($fullModuleCreditsTable) as $module => $authors) {
            $text .= self::creditsTableRowText([$module, $authors]);
        }

        return $text;
    }

    private static function creditsDocsSectionText(): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('PHP Documentation');
        foreach (VmCreditsData::DOCS_CREDITS as $contribution => $authors) {
            $text .= self::creditsTableRowText([$contribution, $authors]);
        }

        return $text;
    }

    private static function creditsWebSectionText(): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('Websites and Infrastructure team');
        foreach (VmCreditsData::WEB_CREDITS as $contribution => $authors) {
            $text .= self::creditsTableRowText([$contribution, $authors]);
        }

        return $text;
    }

    private static function creditsDebianSectionText(): string
    {
        $text = "\n";
        $text .= self::creditsColspanHeaderText('Debian Packaging');
        $text .= VmCreditsData::DEBIAN_PACKAGING_AUTHOR."\n";

        return $text;
    }

    private static function creditsModulesSectionHtml(bool $fullModuleCreditsTable): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>Module Authors</h2></td></tr>';
        $html .= '<tr><td class="e">Module </td><td class="v">Authors </td></tr>';
        foreach (self::creditsModuleAuthorRows($fullModuleCreditsTable) as $module => $authors) {
            $html .= '<tr><td class="e">'.$module.' </td><td class="v">'.$authors.' </td></tr>';
        }
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsDocsSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>PHP Documentation</h2></td></tr>';
        $html .= '<tr><td class="e">Contribution </td><td class="v">Authors </td></tr>';
        foreach (VmCreditsData::DOCS_CREDITS as $contribution => $authors) {
            $html .= '<tr><td class="e">'.$contribution.' </td><td class="v">'.$authors.' </td></tr>';
        }
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsWebSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>Websites and Infrastructure team</h2></td></tr>';
        $html .= '<tr><td class="e">Contribution </td><td class="v">Authors </td></tr>';
        foreach (VmCreditsData::WEB_CREDITS as $contribution => $authors) {
            $html .= '<tr><td class="e">'.$contribution.' </td><td class="v">'.$authors.' </td></tr>';
        }
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsDebianSectionHtml(): string
    {
        $html = '<table><tr class="h"><td colspan="2"><h2>Debian Packaging</h2></td></tr>';
        $html .= '<tr><td class="v" colspan="2">'.VmCreditsData::DEBIAN_PACKAGING_AUTHOR.'</td></tr>';
        $html .= '</table><br />';

        return $html;
    }

    private static function creditsFullPageWrap(string $body): string
    {
        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">';
        $html .= '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
        $html .= '<title>PHP Credits</title><style type="text/css">.h{background-color:#9999cc;font-weight:bold;color:#000000;}';
        $html .= '.v{background-color:#cccccc;color:#000000;}.e{background-color:#ccccff;font-weight:bold;color:#000000;}</style></head>';
        $html .= '<body><div class="center">'.$body.'</div></body></html>';

        return $html;
    }
}
