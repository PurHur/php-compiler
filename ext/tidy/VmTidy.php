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
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function hostFrom(ObjectEntry $object): ?object
    {
        return self::$hostObjects[$object->id] ?? null;
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
            return (bool) $host->cleanRepair();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy::cleanRepair(): '.$e->getMessage());

            return false;
        }
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
