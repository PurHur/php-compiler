<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * Apache subprocess environment helpers — CGI/process environ bridge (#11626).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(apache_getenv), PHP_FUNCTION(apache_setenv)
 * php-src: ext/standard/head.c — PHP_FUNCTION(apache_note), PHP_FUNCTION(apache_get_version)
 */
final class VmApache
{
    private const NOTE_UNAVAILABLE = 'apache_note() expects Apache 2.0 or higher';

    private const VERSION_UNAVAILABLE = 'apache_get_version() expects Apache 2.0 or higher';

    /** @var array<string, string> */
    private static array $notes = [];

    public static function isApacheSapi(): bool
    {
        return \in_array(CompilerVersion::SAPI, ['apache', 'apache2handler'], true);
    }

    public static function getenv(string $variable, bool $walkToTop = false): string|false
    {
        unset($walkToTop);

        return VmEnv::getenv($variable);
    }

    public static function setenv(string $variable, string $value, bool $walkToTop = false): bool
    {
        unset($walkToTop);

        return VmEnv::putenv($variable.'='.$value);
    }

    public static function note(?Frame $frame, string $noteName, ?string $noteValue = null): string|false
    {
        if (!self::isApacheSapi()) {
            self::emitWarning($frame, self::NOTE_UNAVAILABLE);

            return false;
        }
        if (null === $noteValue) {
            return self::$notes[$noteName] ?? false;
        }
        $previous = self::$notes[$noteName] ?? false;
        self::$notes[$noteName] = $noteValue;

        return \is_string($previous) ? $previous : false;
    }

    public static function getVersion(?Frame $frame): string|false
    {
        if (!self::isApacheSapi()) {
            self::emitWarning($frame, self::VERSION_UNAVAILABLE);

            return false;
        }
        $software = VmEnv::getenv('SERVER_SOFTWARE');

        return false !== $software && '' !== $software ? $software : 'Apache';
    }

    public static function noteUnavailableMessage(): string
    {
        return self::NOTE_UNAVAILABLE;
    }

    public static function versionUnavailableMessage(): string
    {
        return self::VERSION_UNAVAILABLE;
    }

    private static function emitWarning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            @\trigger_error($message, \E_WARNING);

            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public static function coerceWalkToTopArg(Variable $var, string $function, int $argIndex): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($walk_to_top) must be of type bool, %s given',
            $function,
            $argIndex + 1,
            match ($var->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            }
        ));
    }
}
