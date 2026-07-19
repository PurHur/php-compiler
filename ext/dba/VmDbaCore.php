<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * dba_* core — open/CRUD dispatch (php-src ext/dba/dba.c; #4422).
 */
final class VmDbaCore
{
    public const HANDLER_FLATFILE = 'flatfile';

    /** @return list<string> */
    public static function handlers(): array
    {
        return [self::HANDLER_FLATFILE];
    }

    public static function open(
        string $path,
        string $mode,
        ?string $handler,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        $handler = null === $handler || '' === $handler ? self::HANDLER_FLATFILE : \strtolower($handler);
        if (self::HANDLER_FLATFILE !== $handler) {
            self::emitWarning($frame, 'dba_open(): Driver initialization failed for handler: '.$handler);

            return false;
        }

        $modeLetter = self::normalizeMode($mode);
        if (null === $modeLetter) {
            self::emitWarning($frame, 'dba_open(): illegal DBA mode specification');

            return false;
        }

        $exists = \is_file($path);
        $writable = 'r' !== $modeLetter;
        if ('r' === $modeLetter || 'w' === $modeLetter) {
            if (!$exists) {
                self::emitWarning(
                    $frame,
                    'dba_open('.$path.'): Failed to open stream: No such file or directory'
                );

                return false;
            }
        }
        if ('n' === $modeLetter && $exists) {
            \unlink($path);
            $exists = false;
        }

        $fopenMode = match ($modeLetter) {
            'r' => 'rb',
            'w' => 'r+b',
            'c' => $exists ? 'r+b' : 'c+b',
            'n' => 'w+b',
            default => 'r+b',
        };
        $fp = @\fopen($path, $fopenMode);
        if (false === $fp) {
            self::emitWarning($frame, 'dba_open('.$path.'): Failed to open stream');

            return false;
        }

        return VmDbaConnection::wrap($path, $modeLetter, $handler, $writable, $fp, $ctx);
    }

    public static function close(ObjectEntry $connection): void
    {
        VmDbaConnection::close($connection);
    }

    public static function insert(ObjectEntry $connection, string $key, string $value): bool
    {
        $state = VmDbaConnection::state($connection);
        if (!$state['writable']) {
            return false;
        }
        /** @var resource $fp */
        $fp = $state['fp'];

        return VmDbaFlatfile::insert($fp, $key, $value);
    }

    public static function replace(ObjectEntry $connection, string $key, string $value): bool
    {
        $state = VmDbaConnection::state($connection);
        if (!$state['writable']) {
            return false;
        }
        /** @var resource $fp */
        $fp = $state['fp'];

        return VmDbaFlatfile::replace($fp, $key, $value);
    }

    public static function fetch(ObjectEntry $connection, string $key): string|false
    {
        $state = VmDbaConnection::state($connection);
        /** @var resource $fp */
        $fp = $state['fp'];
        $val = VmDbaFlatfile::fetch($fp, $key);

        return null === $val ? false : $val;
    }

    public static function exists(ObjectEntry $connection, string $key): bool
    {
        $state = VmDbaConnection::state($connection);
        /** @var resource $fp */
        $fp = $state['fp'];

        return VmDbaFlatfile::exists($fp, $key);
    }

    public static function delete(ObjectEntry $connection, string $key): bool
    {
        $state = VmDbaConnection::state($connection);
        if (!$state['writable']) {
            return false;
        }
        /** @var resource $fp */
        $fp = $state['fp'];

        return VmDbaFlatfile::delete($fp, $key);
    }

    public static function requireConnection(Variable $var, string $function): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type || !VmDbaConnection::isLive($var->toObject())) {
            throw new \TypeError(
                $function.'(): supplied resource is not a valid DBA connection resource'
            );
        }

        return $var->toObject();
    }

    public static function coerceKey(Variable $var, string $function, int $argIndex): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            return self::makeKeyFromArray($var->toArray(), $function);
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, 'key');
    }

    public static function coercePathArg(Variable $var, string $function, int $argIndex, string $name): string
    {
        return VmString::coercePathBuiltinArg($var, $function, $argIndex, $name);
    }

    public static function coerceModeArg(Variable $var, string $function): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, 1, 'mode');
    }

    public static function coerceHandlerArg(Variable $var, string $function): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, 2, 'handler');
    }

    private static function makeKeyFromArray(HashTable $ht, string $function): string
    {
        $parts = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $value = $value->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($value)) {
                throw new \TypeError(
                    $function.'(): Argument #1 ($key) must be of type string|array, '
                    .EnumCaseSupport::typeNameForVariable($value).' given in key array'
                );
            }
            $parts[] = VmString::coerceStringBuiltinArg($value, $function, 0, 'key');
        }
        if (2 !== \count($parts)) {
            throw new \Error('Key does not have the right number of elements - expecting 2');
        }

        return $parts[0]."\0".$parts[1];
    }

    private static function normalizeMode(string $mode): ?string
    {
        $mode = \strtolower($mode);
        if ('' === $mode) {
            return null;
        }
        $letter = $mode[0];
        if (!\in_array($letter, ['r', 'w', 'c', 'n'], true)) {
            return null;
        }

        return $letter;
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
            '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
