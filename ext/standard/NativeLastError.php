<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Zend-style last-error state for VM + JIT/AOT (ext/standard/basic_functions.c; issue #5534, #9454).
 *
 * VM delegates to {@see ErrorLastJitHelper}; JIT/AOT compile that helper into the module.
 */
final class NativeLastError
{
    public static function clear(): void
    {
        ErrorLastJitHelper::clear();
    }

    public static function isActive(): bool
    {
        return ErrorLastJitHelper::isActive();
    }

    public static function record(int $type, string $message, ?string $file, int $line): void
    {
        ErrorLastJitHelper::record($type, $message, null !== $file ? $file : '', $line);
    }

    public static function toHashTable(): ?HashTable
    {
        if (!ErrorLastJitHelper::isActive()) {
            return null;
        }

        $ht = new HashTable();
        $typeVar = new Variable(Variable::TYPE_INTEGER);
        $typeVar->int(ErrorLastJitHelper::getType());
        $ht->add('type', $typeVar);
        $messageVar = new Variable(Variable::TYPE_STRING);
        $messageVar->string(ErrorLastJitHelper::getMessage());
        $ht->add('message', $messageVar);
        $fileVar = new Variable(Variable::TYPE_STRING);
        $fileVar->string(ErrorLastJitHelper::getFile());
        $ht->add('file', $fileVar);
        $lineVar = new Variable(Variable::TYPE_INTEGER);
        $lineVar->int(ErrorLastJitHelper::getLine());
        $ht->add('line', $lineVar);

        return $ht;
    }

    public static function getLastErrorVariable(): Variable
    {
        $out = new Variable();
        $ht = self::toHashTable();
        if (null === $ht) {
            $out->null();

            return $out;
        }
        $out->array($ht);

        return $out;
    }
}
