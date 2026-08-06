<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Variable;

/**
 * Core PHP engine predefined constants (php-src: main/main.c register_php_constants,
 * Zend/zend_constants.c).
 */
final class VmPhpCoreConstants
{
    /** php-src ZEND_ACC_TENTATIVE_RETURN exposed as TENTATIVE_RETURN (PHP 8.4+, zend_attributes.h). */
    public const TENTATIVE_RETURN = 1;

    /**
     * Names registered in Zend get_defined_constants(true)['Core'] with PHP_ prefix.
     *
     * @var list<string>
     */
    /** Core path constants (main/main.c REGISTER_MAIN_STRINGL_CONSTANT). */
    private const PATH_CONSTANT_NAMES = [
        'DIRECTORY_SEPARATOR',
        'PATH_SEPARATOR',
    ];

    /** Zend Core bucket upload error codes (main/main.c). */
    private const UPLOAD_ERR_VALUES = [
        'UPLOAD_ERR_OK' => 0,
        'UPLOAD_ERR_INI_SIZE' => 1,
        'UPLOAD_ERR_FORM_SIZE' => 2,
        'UPLOAD_ERR_PARTIAL' => 3,
        'UPLOAD_ERR_NO_FILE' => 4,
        'UPLOAD_ERR_NO_TMP_DIR' => 6,
        'UPLOAD_ERR_CANT_WRITE' => 7,
        'UPLOAD_ERR_EXTENSION' => 8,
    ];

    private const CORE_NAMES = [
        'PHP_VERSION',
        'PHP_MAJOR_VERSION',
        'PHP_MINOR_VERSION',
        'PHP_RELEASE_VERSION',
        'PHP_EXTRA_VERSION',
        'PHP_VERSION_ID',
        'PHP_ZTS',
        'PHP_DEBUG',
        'PHP_OS',
        'PHP_OS_FAMILY',
        'PHP_SAPI',
        'PHP_EXTENSION_DIR',
        'PHP_PREFIX',
        'PHP_BINDIR',
        'PHP_MANDIR',
        'PHP_LIBDIR',
        'PHP_DATADIR',
        'PHP_SYSCONFDIR',
        'PHP_LOCALSTATEDIR',
        'PHP_CONFIG_FILE_PATH',
        'PHP_CONFIG_FILE_SCAN_DIR',
        'PHP_SHLIB_SUFFIX',
        'PHP_EOL',
        'PHP_MAXPATHLEN',
        'PHP_INT_MAX',
        'PHP_INT_MIN',
        'PHP_INT_SIZE',
        'PHP_FD_SETSIZE',
        'PHP_FLOAT_DIG',
        'PHP_FLOAT_EPSILON',
        'PHP_FLOAT_MAX',
        'PHP_FLOAT_MIN',
        'PHP_BINARY',
        'PHP_OUTPUT_HANDLER_START',
        'PHP_OUTPUT_HANDLER_WRITE',
        'PHP_OUTPUT_HANDLER_FLUSH',
        'PHP_OUTPUT_HANDLER_CLEAN',
        'PHP_OUTPUT_HANDLER_FINAL',
        'PHP_OUTPUT_HANDLER_CONT',
        'PHP_OUTPUT_HANDLER_END',
        'PHP_OUTPUT_HANDLER_CLEANABLE',
        'PHP_OUTPUT_HANDLER_FLUSHABLE',
        'PHP_OUTPUT_HANDLER_REMOVABLE',
        'PHP_OUTPUT_HANDLER_STDFLAGS',
        'PHP_OUTPUT_HANDLER_STARTED',
        'PHP_OUTPUT_HANDLER_DISABLED',
        'PHP_CLI_PROCESS_TITLE',
    ];

    public static function fetch(string $name): ?Variable
    {
        $path = self::pathConstantValue($name);
        if (null !== $path) {
            return self::fromPhpValue($path);
        }
        $mainExtra = self::mainCoreExtraValueLoose($name);
        if (null !== $mainExtra) {
            return self::fromPhpValue($mainExtra);
        }
        $compiler = self::compilerVersionConstantLoose($name);
        if (null !== $compiler) {
            return self::fromPhpValue($compiler);
        }
        $forward = self::forwardProfileCoreConstantLoose($name);
        if (null !== $forward) {
            return self::fromPhpValue($forward);
        }
        $canonical = self::canonicalName($name);
        if (null === $canonical || !\defined($canonical)) {
            return null;
        }

        return self::fromPhpValue(\constant($canonical));
    }

    /** Case-exact fetch for defined()/constant() (#10635, basic_functions.c). */
    public static function fetchExact(string $name): ?Variable
    {
        $path = self::pathConstantValue($name);
        if (null !== $path) {
            return self::fromPhpValue($path);
        }
        $mainExtra = self::mainCoreExtraValueExact($name);
        if (null !== $mainExtra) {
            return self::fromPhpValue($mainExtra);
        }
        $compiler = self::compilerVersionConstantExact($name);
        if (null !== $compiler) {
            return self::fromPhpValue($compiler);
        }
        $forward = self::forwardProfileCoreConstantExact($name);
        if (null !== $forward) {
            return self::fromPhpValue($forward);
        }
        if (!\in_array($name, self::CORE_NAMES, true) || !\defined($name)) {
            return null;
        }

        return self::fromPhpValue(\constant($name));
    }

    /**
     * @return array<string, Variable>
     */
    public static function definedCoreEntries(): array
    {
        $entries = [];
        foreach (self::PATH_CONSTANT_NAMES as $canonical) {
            $var = self::fromPhpValue(self::pathConstantValue($canonical));
            if (null !== $var) {
                $entries[$canonical] = $var;
            }
        }
        foreach (self::categorizedCoreScalarEntries() as $name => $var) {
            $entries[$name] = $var;
        }

        return $entries;
    }

    /**
     * Zend get_defined_constants(true)['Core'] entries — excludes ext/standard module constants (#4840).
     *
     * @return array<string, Variable>
     */
    public static function categorizedCoreEntries(): array
    {
        $entries = self::categorizedCoreScalarEntries();
        foreach (self::UPLOAD_ERR_VALUES as $name => $value) {
            $var = self::fromPhpValue($value);
            if (null !== $var) {
                $entries[$name] = $var;
            }
        }
        foreach (['DEFAULT_INCLUDE_PATH', 'PEAR_INSTALL_DIR', 'PEAR_EXTENSION_DIR', 'ZEND_THREAD_SAFE', 'ZEND_DEBUG_BUILD'] as $name) {
            $value = self::mainCoreExtraValueExact($name);
            if (null === $value) {
                continue;
            }
            $var = self::fromPhpValue($value);
            if (null !== $var) {
                $entries[$name] = $var;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, Variable>
     */
    private static function categorizedCoreScalarEntries(): array
    {
        $entries = [];
        foreach (self::CORE_NAMES as $canonical) {
            $value = self::compilerVersionConstantExact($canonical);
            if (null === $value && \defined($canonical)) {
                $value = \constant($canonical);
            }
            if (null === $value) {
                continue;
            }
            $var = self::fromPhpValue($value);
            if (null !== $var) {
                $entries[$canonical] = $var;
            }
        }
        foreach (self::forwardProfileCoreConstants() as $canonical => $value) {
            $var = self::fromPhpValue($value);
            if (null !== $var) {
                $entries[$canonical] = $var;
            }
        }

        return $entries;
    }

    /**
     * Profile-gated Core constants (TENTATIVE_RETURN / PHP_SBINDIR on ≥8.4, PHP_BUILD_DATE on ≥8.5).
     *
     * @return array<string, int|string>
     */
    private static function forwardProfileCoreConstants(): array
    {
        $out = [];
        if (CompilerVersion::supportsTentativeReturnConstant()) {
            $out['TENTATIVE_RETURN'] = self::TENTATIVE_RETURN;
        }
        if (CompilerVersion::supportsPhpSbindirConstant()) {
            $out['PHP_SBINDIR'] = self::phpSbindirValue();
        }
        if (CompilerVersion::supportsPhpBuildDateConstant()) {
            $out['PHP_BUILD_DATE'] = CompilerVersion::phpBuildDateStamp();
        }

        return $out;
    }

    /**
     * Configure {@code --sbindir} path for {@see PHP_SBINDIR} (main/main.c; #28170).
     *
     * Prefer host {@code PHP_SBINDIR} when the engine defines it (PHP 8.4+ / php-config
     * {@code --sbindir}). Otherwise derive from {@code PHP_BINDIR} / {@code PHP_PREFIX}
     * ({@code …/bin} → {@code …/sbin}), matching Autoconf {@code sbindir} vs {@code bindir}.
     */
    public static function phpSbindirValue(): string
    {
        if (\defined('PHP_SBINDIR')) {
            $host = (string) \constant('PHP_SBINDIR');
            if ('' !== $host) {
                return $host;
            }
        }
        if (\defined('PHP_BINDIR')) {
            $bindir = (string) \constant('PHP_BINDIR');
            if (str_ends_with($bindir, '/bin')) {
                return substr($bindir, 0, -4).'/sbin';
            }
            if (str_ends_with($bindir, '\\bin')) {
                return substr($bindir, 0, -4).'\\sbin';
            }
        }
        if (\defined('PHP_PREFIX')) {
            $prefix = rtrim((string) \constant('PHP_PREFIX'), '/\\');

            return $prefix.(self::isWindowsPlatform() ? '\\sbin' : '/sbin');
        }

        return self::isWindowsPlatform() ? 'C:\\php\\sbin' : '/usr/sbin';
    }

    /** @return array<string, int> */
    private static function forwardProfileCoreIntConstants(): array
    {
        $out = [];
        foreach (self::forwardProfileCoreConstants() as $name => $value) {
            if (\is_int($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    private static function forwardProfileCoreConstantLoose(string $name): int|string|null
    {
        $upper = strtoupper($name);
        foreach (self::forwardProfileCoreConstants() as $canonical => $value) {
            if (strtoupper($canonical) === $upper) {
                return $value;
            }
        }

        return null;
    }

    private static function forwardProfileCoreConstantExact(string $name): int|string|null
    {
        return self::forwardProfileCoreConstants()[$name] ?? null;
    }

    public static function pathSeparatorValue(): string
    {
        return self::pathConstantValue('PATH_SEPARATOR') ?? (self::isWindowsPlatform() ? ';' : ':');
    }

    public static function directorySeparatorValue(): string
    {
        return self::pathConstantValue('DIRECTORY_SEPARATOR') ?? (self::isWindowsPlatform() ? '\\' : '/');
    }

    /**
     * main/main.c + Zend/zend_constants.c extras already listed in categorizedCoreEntries()
     * but historically omitted from fetch/fetchExact (#24081).
     *
     * @return int|string|bool|null
     */
    private static function mainCoreExtraValueExact(string $name): int|string|bool|null
    {
        if (\array_key_exists($name, self::UPLOAD_ERR_VALUES)) {
            return self::UPLOAD_ERR_VALUES[$name];
        }

        return match ($name) {
            'DEFAULT_INCLUDE_PATH' => self::defaultIncludePath(),
            'PEAR_INSTALL_DIR' => self::pearInstallDir(),
            'PEAR_EXTENSION_DIR' => self::pearExtensionDir(),
            // Match categorizedCoreEntries() historically: PHP_ZTS/PHP_DEBUG via CompilerVersion only
            // (host PHP_* live in CORE_NAMES scalar path; ZEND_* mirror the 0-default when unset).
            'ZEND_THREAD_SAFE' => (bool) (self::compilerVersionConstantExact('PHP_ZTS') ?? 0),
            'ZEND_DEBUG_BUILD' => (bool) (self::compilerVersionConstantExact('PHP_DEBUG') ?? 0),
            default => null,
        };
    }

    /** @return int|string|bool|null */
    private static function mainCoreExtraValueLoose(string $name): int|string|bool|null
    {
        $upper = strtoupper($name);
        if (\array_key_exists($upper, self::UPLOAD_ERR_VALUES)) {
            return self::UPLOAD_ERR_VALUES[$upper];
        }

        return match ($upper) {
            'DEFAULT_INCLUDE_PATH' => self::defaultIncludePath(),
            'PEAR_INSTALL_DIR' => self::pearInstallDir(),
            'PEAR_EXTENSION_DIR' => self::pearExtensionDir(),
            'ZEND_THREAD_SAFE' => self::mainCoreExtraValueExact('ZEND_THREAD_SAFE'),
            'ZEND_DEBUG_BUILD' => self::mainCoreExtraValueExact('ZEND_DEBUG_BUILD'),
            default => null,
        };
    }

    private static function defaultIncludePath(): string
    {
        if (\defined('DEFAULT_INCLUDE_PATH')) {
            return (string) \constant('DEFAULT_INCLUDE_PATH');
        }

        return '.:'.self::pearInstallDir();
    }

    private static function pearInstallDir(): string
    {
        if (\defined('PEAR_INSTALL_DIR')) {
            return (string) \constant('PEAR_INSTALL_DIR');
        }

        return '/usr/share/php';
    }

    private static function pearExtensionDir(): string
    {
        if (\defined('PEAR_EXTENSION_DIR')) {
            return (string) \constant('PEAR_EXTENSION_DIR');
        }

        return self::pearInstallDir().'/ext';
    }

    private static function pathConstantValue(string $name): ?string
    {
        $canonical = match (strtoupper($name)) {
            'DIRECTORY_SEPARATOR' => 'DIRECTORY_SEPARATOR',
            'PATH_SEPARATOR' => 'PATH_SEPARATOR',
            default => null,
        };
        if (null === $canonical) {
            return null;
        }
        if (\defined($canonical)) {
            /** @var string $value */
            $value = \constant($canonical);

            return $value;
        }

        return 'DIRECTORY_SEPARATOR' === $canonical
            ? (self::isWindowsPlatform() ? '\\' : '/')
            : (self::isWindowsPlatform() ? ';' : ':');
    }

    private static function isWindowsPlatform(): bool
    {
        if (\defined('PHP_OS_FAMILY')) {
            return 'Windows' === \PHP_OS_FAMILY;
        }
        if (\defined('PHP_OS')) {
            return str_starts_with(\PHP_OS, 'WIN');
        }

        return false;
    }

    private static function canonicalName(string $name): ?string
    {
        // Zend resolves parent/self/static as magic constants in class scope; defined() throws
        // when the current class has no parent (#1492 bootstrap-selfhost-helloworld).
        if (in_array(strtolower($name), ['parent', 'self', 'static'], true)) {
            return null;
        }
        // Qualified names like parent::CONST throw from defined() inside a class; core constants are bare.
        if (str_contains($name, '::')) {
            return null;
        }
        // Only Core PHP_* names — do not treat host ext/standard constants (IMAGETYPE_*, …) as
        // engine core or they shadow module bootstrap under a different language profile (#22787).
        $upper = strtoupper($name);
        if (!str_starts_with($upper, 'PHP_')) {
            return null;
        }
        foreach (self::CORE_NAMES as $canonical) {
            if (strtoupper($canonical) === $upper) {
                return $canonical;
            }
        }

        return null;
    }

    /** Runtime version constants — SSOT {@see CompilerVersion} / phpversion() (#11470). */
    private static function compilerVersionConstantLoose(string $name): mixed
    {
        $canonical = self::canonicalName($name);
        if (null === $canonical) {
            return null;
        }

        return self::compilerVersionConstantExact($canonical);
    }

    private static function compilerVersionConstantExact(string $canonical): mixed
    {
        return match ($canonical) {
            'PHP_VERSION' => CompilerVersion::reportedPhpVersion(),
            'PHP_MAJOR_VERSION' => CompilerVersion::reportedPhpMajorVersion(),
            'PHP_MINOR_VERSION' => CompilerVersion::reportedPhpMinorVersion(),
            'PHP_RELEASE_VERSION' => CompilerVersion::reportedPhpReleaseVersion(),
            'PHP_EXTRA_VERSION' => CompilerVersion::reportedPhpExtraVersion(),
            'PHP_VERSION_ID' => CompilerVersion::reportedPhpVersionId(),
            default => null,
        };
    }

    private static function fromPhpValue(mixed $value): ?Variable
    {
        if (\is_int($value)) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);

            return $var;
        }
        if (\is_float($value)) {
            $var = new Variable(Variable::TYPE_FLOAT);
            $var->float($value);

            return $var;
        }
        if (\is_bool($value)) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool($value);

            return $var;
        }
        if (\is_string($value)) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($value);

            return $var;
        }

        return null;
    }
}
