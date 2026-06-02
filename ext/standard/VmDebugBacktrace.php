<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPCompiler\VM\Variable;

/**
 * VM debug_backtrace() — stack frames (file, line, function, optional args/object) (#1378, #3626).
 */
final class VmDebugBacktrace
{
    public const PROVIDE_OBJECT = 1;
    public const IGNORE_ARGS = 2;
    public const IGNORE_STATIC_ARGS = 4;

    public static function build(Frame $frame, int $options = 0, int $limit = 0): Variable
    {
        $includeArgs = 0 === ($options & self::IGNORE_ARGS);
        $provideObject = 0 !== ($options & self::PROVIDE_OBJECT);

        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();

        $framesAdded = 0;
        $walk = [];
        if ($frame->hasHandler() && null !== $frame->parent) {
            $walk[] = $frame->parent;
        }
        if (null !== $frame->vmContext) {
            foreach ($frame->vmContext->runStackFrames() as $stackFrame) {
                $walk[] = $stackFrame;
            }
        } elseif ([] === $walk) {
            $walk = self::parentChainFrames($frame);
        }
        foreach ($walk as $f) {
            if ($f->hasHandler()) {
                continue;
            }
            $entry = self::frameEntry($f, $includeArgs, $provideObject);
            if (null === $entry) {
                continue;
            }
            $ht->append($entry);
            ++$framesAdded;
            if ($limit > 0 && $framesAdded >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return list<Frame>
     */
    private static function parentChainFrames(Frame $frame): array
    {
        $frames = [];
        $start = $frame->hasHandler() && null !== $frame->parent ? $frame->parent : $frame;
        for ($f = $start; null !== $f; $f = $f->parent) {
            $frames[] = $f;
        }

        return $frames;
    }

    private static function frameEntry(Frame $frame, bool $includeArgs, bool $provideObject): ?Variable
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

        if ($provideObject) {
            $object = self::frameObject($frame);
            if (null !== $object) {
                $ht->add('object', $object);
            }
        }

        if ($includeArgs) {
            $args = SensitiveParamSupport::buildArgsArray($frame);
            if (null !== $args) {
                $ht->add('args', $args);
            }
        }

        return $entry;
    }

    private static function frameObject(Frame $frame): ?Variable
    {
        if (null === $frame->block || null === $frame->block->func || null === $frame->block->func->class) {
            return null;
        }
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null === $thisIdx || !isset($frame->scope[$thisIdx])) {
            return null;
        }
        $thisVar = $frame->scope[$thisIdx]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thisVar->type) {
            return null;
        }

        return $thisVar;
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
