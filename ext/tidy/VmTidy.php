<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * Host-bridge tidy helpers (php-src ext/tidy/tidy.c; #21464).
 *
 * When the harness PHP has ext/tidy, parse/cleanRepair delegate to host `\tidy_*`.
 * Without host tidy, parse returns false (compliance SKIPIF).
 */
final class VmTidy
{
    public const CLASS_LC = 'tidy';

    /** @var array<int, object> ObjectEntry id => host \tidy instance */
    private static array $hostObjects = [];

    public static function hostAvailable(): bool
    {
        return \extension_loaded('tidy') && \function_exists('tidy_parse_string');
    }

    public static function requireClass(Context $ctx): ClassEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \LogicException('tidy builtin class is not registered');
        }

        return $ctx->classes[self::CLASS_LC];
    }

    /**
     * @return Variable|false tidy object variable, or false when host tidy missing/parse fails
     */
    public static function parseString(Context $ctx, string $html, ?Frame $frame)
    {
        if (!self::hostAvailable()) {
            self::emitWarning($frame, 'tidy_parse_string(): host ext/tidy is not available');

            return false;
        }

        try {
            $host = \tidy_parse_string($html);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_parse_string(): '.$e->getMessage());

            return false;
        }
        if (false === $host || !\is_object($host)) {
            return false;
        }

        return self::wrapHost($ctx, $host);
    }

    public static function wrapHost(Context $ctx, object $host): Variable
    {
        $entry = new ObjectEntry(self::requireClass($ctx));
        $entry->constructed = true;
        self::$hostObjects[$entry->id] = $host;
        self::syncHostProperties($entry, $host);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function hostFrom(ObjectEntry $object): ?object
    {
        return self::$hostObjects[$object->id] ?? null;
    }

    public static function requireTidyObject(Variable $arg, string $func, int $argNum): ObjectEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($tidy) must be of type tidy, %s given',
                $func,
                $argNum + 1,
                self::typeLabel($arg)
            ));
        }
        $object = $arg->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($tidy) must be of type tidy, %s given',
                $func,
                $argNum + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }

    public static function cleanRepair(ObjectEntry $object, ?Frame $frame): bool
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy::cleanRepair(): tidy object has no host backend');

            return false;
        }
        if (!\is_callable([$host, 'cleanRepair'])) {
            self::emitWarning($frame, 'tidy::cleanRepair(): host tidy lacks cleanRepair()');

            return false;
        }

        try {
            $ok = (bool) $host->cleanRepair();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy::cleanRepair(): '.$e->getMessage());

            return false;
        }
        self::syncValueProperty($object, $host);

        return $ok;
    }

    /**
     * tidy_diagnose() / tidy::diagnose() — host bridge (#21500).
     */
    public static function diagnose(ObjectEntry $object, ?Frame $frame): bool
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_diagnose(): tidy object has no host backend');

            return false;
        }
        if (!\is_callable([$host, 'diagnose'])) {
            self::emitWarning($frame, 'tidy_diagnose(): host tidy lacks diagnose()');

            return false;
        }

        try {
            $ok = (bool) $host->diagnose();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_diagnose(): '.$e->getMessage());

            return false;
        }
        self::syncHostProperties($object, $host);

        return $ok;
    }

    /**
     * tidy_get_error_buffer() / $tidy->errorBuffer — host bridge (#21500).
     *
     * @return string|false
     */
    public static function getErrorBuffer(ObjectEntry $object, ?Frame $frame)
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_get_error_buffer(): tidy object has no host backend');

            return false;
        }
        try {
            if (\function_exists('tidy_get_error_buffer')) {
                $buf = \tidy_get_error_buffer($host);
            } else {
                $buf = $host->errorBuffer ?? false;
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_error_buffer(): '.$e->getMessage());

            return false;
        }
        if (false === $buf) {
            self::syncErrorBufferProperty($object, null);

            return false;
        }
        $str = (string) $buf;
        self::syncErrorBufferProperty($object, $str);

        return $str;
    }

    /**
     * tidy_get_output() / $tidy->value — host document string (#21499).
     */
    public static function getOutput(ObjectEntry $object, ?Frame $frame): string
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_get_output(): tidy object has no host backend');

            return '';
        }
        try {
            if (\function_exists('tidy_get_output')) {
                $out = \tidy_get_output($host);
            } else {
                $out = $host->value ?? '';
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_output(): '.$e->getMessage());

            return '';
        }
        $str = false === $out || null === $out ? '' : (string) $out;
        self::syncValueProperty($object, $host, $str);

        return $str;
    }

    /** Keep public $value in sync with host tidy (#21499). */
    public static function syncValueProperty(ObjectEntry $object, object $host, ?string $forced = null): void
    {
        if (!$object->hasProperty('value')) {
            return;
        }
        $slot = $object->getProperty('value');
        if (null !== $forced) {
            $slot->string($forced);

            return;
        }
        try {
            $raw = $host->value ?? null;
        } catch (\Throwable $e) {
            $slot->null();

            return;
        }
        if (null === $raw) {
            $slot->null();

            return;
        }
        $slot->string((string) $raw);
    }

    /** Keep public $errorBuffer in sync with host tidy (#21500). */
    public static function syncErrorBufferProperty(ObjectEntry $object, ?string $forced): void
    {
        if (!$object->hasProperty('errorBuffer')) {
            return;
        }
        $slot = $object->getProperty('errorBuffer');
        if (null === $forced) {
            $slot->null();

            return;
        }
        $slot->string($forced);
    }

    public static function syncHostProperties(ObjectEntry $object, object $host): void
    {
        self::syncValueProperty($object, $host);
        try {
            $buf = $host->errorBuffer ?? null;
        } catch (\Throwable $e) {
            self::syncErrorBufferProperty($object, null);

            return;
        }
        self::syncErrorBufferProperty($object, null === $buf ? null : (string) $buf);
    }

    /**
     * tidy_repair_string() / tidy::repairString() — host bridge (#21498).
     *
     * @return string|false
     */
    public static function repairString(string $html, ?Frame $frame)
    {
        if (!self::hostAvailable() || !\function_exists('tidy_repair_string')) {
            self::emitWarning($frame, 'tidy_repair_string(): host ext/tidy is not available');

            return false;
        }

        try {
            $out = \tidy_repair_string($html);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_repair_string(): '.$e->getMessage());

            return false;
        }
        if (false === $out) {
            return false;
        }

        return (string) $out;
    }

    /**
     * tidy_repair_file() / tidy::repairFile() — host bridge (#21498).
     *
     * @return string|false
     */
    public static function repairFile(string $filename, ?Frame $frame)
    {
        if (!self::hostAvailable() || !\function_exists('tidy_repair_file')) {
            self::emitWarning($frame, 'tidy_repair_file(): host ext/tidy is not available');

            return false;
        }

        try {
            $out = \tidy_repair_file($filename);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_repair_file(): '.$e->getMessage());

            return false;
        }
        if (false === $out) {
            return false;
        }

        return (string) $out;
    }

    public static function htmlStringArg(Variable $arg, string $func, int $argNum): string
    {
        return VmString::coerceStringBuiltinArg($arg, $func, $argNum, 'string');
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
}
