<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Zend-style last-error state for VM (ext/standard/basic_functions.c; issue #5534).
 *
 * JIT/AOT use {@see \PHPCompiler\JIT\Builtin\LastErrorRuntime} LLVM globals with the same semantics.
 */
final class NativeLastError
{
    /** @var array{type: int, message: string, file: string, line: int}|null */
    private static ?array $lastError = null;

    public static function clear(): void
    {
        self::$lastError = null;
    }

    public static function isActive(): bool
    {
        return null !== self::$lastError;
    }

    public static function record(int $type, string $message, ?string $file, int $line): void
    {
        self::$lastError = [
            'type' => $type,
            'message' => $message,
            'file' => null !== $file ? $file : '',
            'line' => $line,
        ];
    }

    public static function getLastErrorVariable(): Variable
    {
        $out = new Variable();
        if (null === self::$lastError) {
            $out->null();

            return $out;
        }
        $ht = new HashTable();
        $typeVar = new Variable(Variable::TYPE_INTEGER);
        $typeVar->int(self::$lastError['type']);
        $ht->add('type', $typeVar);
        $messageVar = new Variable(Variable::TYPE_STRING);
        $messageVar->string(self::$lastError['message']);
        $ht->add('message', $messageVar);
        $fileVar = new Variable(Variable::TYPE_STRING);
        $fileVar->string(self::$lastError['file']);
        $ht->add('file', $fileVar);
        $lineVar = new Variable(Variable::TYPE_INTEGER);
        $lineVar->int(self::$lastError['line']);
        $ht->add('line', $lineVar);
        $out->array($ht);

        return $out;
    }
}
