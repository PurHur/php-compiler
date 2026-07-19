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

    public const HANDLER_INIFILE = 'inifile';

    /** @return list<string> */
    public static function handlers(): array
    {
        return [self::HANDLER_FLATFILE, self::HANDLER_INIFILE];
    }

    public static function open(
        string $path,
        string $mode,
        ?string $handler,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        $handler = null === $handler || '' === $handler ? self::HANDLER_FLATFILE : \strtolower($handler);
        if (!\in_array($handler, self::handlers(), true)) {
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

        return self::HANDLER_INIFILE === $state['handler']
            ? VmDbaInifile::insert($fp, $key, $value)
            : VmDbaFlatfile::insert($fp, $key, $value);
    }

    public static function replace(ObjectEntry $connection, string $key, string $value): bool
    {
        $state = VmDbaConnection::state($connection);
        if (!$state['writable']) {
            return false;
        }
        /** @var resource $fp */
        $fp = $state['fp'];

        return self::HANDLER_INIFILE === $state['handler']
            ? VmDbaInifile::replace($fp, $key, $value)
            : VmDbaFlatfile::replace($fp, $key, $value);
    }

    public static function fetch(ObjectEntry $connection, string $key): string|false
    {
        $state = VmDbaConnection::state($connection);
        /** @var resource $fp */
        $fp = $state['fp'];
        $val = self::HANDLER_INIFILE === $state['handler']
            ? VmDbaInifile::fetch($fp, $key)
            : VmDbaFlatfile::fetch($fp, $key);

        return null === $val ? false : $val;
    }

    public static function exists(ObjectEntry $connection, string $key): bool
    {
        $state = VmDbaConnection::state($connection);
        /** @var resource $fp */
        $fp = $state['fp'];

        return self::HANDLER_INIFILE === $state['handler']
            ? VmDbaInifile::exists($fp, $key)
            : VmDbaFlatfile::exists($fp, $key);
    }

    public static function delete(ObjectEntry $connection, string $key): bool
    {
        $state = VmDbaConnection::state($connection);
        if (!$state['writable']) {
            return false;
        }
        /** @var resource $fp */
        $fp = $state['fp'];

        return self::HANDLER_INIFILE === $state['handler']
            ? VmDbaInifile::delete($fp, $key)
            : VmDbaFlatfile::delete($fp, $key);
    }

    public static function firstKey(ObjectEntry $connection): string|false
    {
        $state = VmDbaConnection::state($connection);
        /** @var resource $fp */
        $fp = $state['fp'];
        if (self::HANDLER_INIFILE === $state['handler']) {
            [$key, $cursor] = VmDbaInifile::firstKey($fp);
        } else {
            [$key, $cursor] = VmDbaFlatfile::firstKey($fp);
        }
        VmDbaConnection::mutate($connection, static function (array &$row) use ($cursor): void {
            $row['cursor'] = $cursor;
        });

        return null === $key ? false : $key;
    }

    public static function nextKey(ObjectEntry $connection): string|false
    {
        $state = VmDbaConnection::state($connection);
        /** @var resource $fp */
        $fp = $state['fp'];
        if (self::HANDLER_INIFILE === $state['handler']) {
            [$key, $cursor] = VmDbaInifile::nextKey($fp, (int) $state['cursor']);
        } else {
            [$key, $cursor] = VmDbaFlatfile::nextKey($fp, (int) $state['cursor']);
        }
        VmDbaConnection::mutate($connection, static function (array &$row) use ($cursor): void {
            $row['cursor'] = $cursor;
        });

        return null === $key ? false : $key;
    }

    public static function optimize(ObjectEntry $connection): bool
    {
        VmDbaConnection::state($connection);

        return true;
    }

    public static function sync(ObjectEntry $connection): bool
    {
        $state = VmDbaConnection::state($connection);
        /** @var resource $fp */
        $fp = $state['fp'];
        \fflush($fp);

        return true;
    }

    /**
     * @return array{0: string, 1: string}|false
     */
    public static function keySplit(mixed $key): array|false
    {
        if (null === $key || false === $key) {
            return false;
        }
        if (!\is_string($key)) {
            throw new \TypeError(
                'dba_key_split(): Argument #1 ($key) must be of type string|false|null, '
                .\get_debug_type($key).' given'
            );
        }
        if (\str_starts_with($key, '[') && false !== ($close = \strpos($key, ']'))) {
            return [\substr($key, 1, $close - 1), \substr($key, $close + 1)];
        }

        return ['', $key];
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
