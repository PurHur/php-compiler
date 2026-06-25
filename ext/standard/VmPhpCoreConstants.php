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
        $compiler = self::compilerVersionConstantLoose($name);
        if (null !== $compiler) {
            return self::fromPhpValue($compiler);
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
        $compiler = self::compilerVersionConstantExact($name);
        if (null !== $compiler) {
            return self::fromPhpValue($compiler);
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

        return $entries;
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
        if (\defined($name)) {
            return $name;
        }
        $upper = strtoupper($name);
        if (\defined($upper)) {
            return $upper;
        }
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
            'PHP_VERSION' => CompilerVersion::VERSION,
            'PHP_MAJOR_VERSION' => CompilerVersion::MAJOR_VERSION,
            'PHP_MINOR_VERSION' => CompilerVersion::MINOR_VERSION,
            'PHP_RELEASE_VERSION' => CompilerVersion::RELEASE_VERSION,
            'PHP_EXTRA_VERSION' => CompilerVersion::EXTRA_VERSION,
            'PHP_VERSION_ID' => CompilerVersion::VERSION_ID,
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
