<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * VM debug_backtrace() — minimal stack frames (file, line, function) (issue #1378).
 */
final class VmDebugBacktrace
{
    public static function build(Frame $frame): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();

        for ($f = $frame; null !== $f; $f = $f->parent) {
            $entry = self::frameEntry($f);
            if (null === $entry) {
                continue;
            }
            $ht->append($entry);
        }

        return $result;
    }

    private static function frameEntry(Frame $frame): ?Variable
    {
        $function = self::frameFunction($frame);
        $file = self::frameFile($frame);
        if ('' === $function && '' === $file) {
            return null;
        }

        $entry = new Variable();
        $entry->newArray();
        $ht = $entry->toArray();

        $fileVar = new Variable(Variable::TYPE_STRING);
        $fileVar->string($file);
        $ht->add('file', $fileVar);

        $lineVar = new Variable(Variable::TYPE_INTEGER);
        $lineVar->int(self::frameLine($frame));
        $ht->add('line', $lineVar);

        $fnVar = new Variable(Variable::TYPE_STRING);
        $fnVar->string($function);
        $ht->add('function', $fnVar);

        return $entry;
    }

    private static function frameFunction(Frame $frame): string
    {
        if ($frame->hasHandler()) {
            return $frame->handler->getName();
        }
        if (null === $frame->block || null === $frame->block->func) {
            return '';
        }
        $func = $frame->block->func;
        $name = $func->name;
        if (null !== $func->class) {
            $class = $func->class->value ?? $func->class->name ?? '';

            return '' !== $class ? $class.'::'.$name : $name;
        }

        return $name;
    }

    private static function frameFile(Frame $frame): string
    {
        if ($frame->hasHandler() && null !== $frame->parent) {
            return self::frameFile($frame->parent);
        }
        if (null !== $frame->block) {
            return $frame->block->scriptPath();
        }
        if ('' !== $frame->scriptPath) {
            return $frame->scriptPath;
        }

        return '';
    }

    private static function frameLine(Frame $frame): int
    {
        // Opcode positions are not mapped to source lines in the VM yet (#1378).
        unset($frame);

        return 0;
    }
}
