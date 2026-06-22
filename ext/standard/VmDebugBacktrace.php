<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCfg\Func;
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
        if (self::isTopLevelBacktraceCall($frame)) {
            return $result;
        }
        $ht = $result->toArray();

        $framesAdded = 0;
        foreach (self::collectFrames($frame) as $f) {
            if ($f->hasHandler()) {
                continue;
            }
            $entry = self::frameEntry($f, $includeArgs, $provideObject, false);
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
     * debug_print_backtrace() — Zend flat formatter (ext/standard/debug_backtrace.c, #3314).
     */
    public static function printFlat(Frame $frame, int $options = 0, int $limit = 0): void
    {
        $includeArgs = 0 === ($options & self::IGNORE_ARGS);
        if (self::isTopLevelBacktraceCall($frame)) {
            return;
        }
        $index = 0;
        $printed = 0;
        foreach (self::collectFrames($frame) as $f) {
            if ($f->hasHandler()) {
                continue;
            }
            $line = self::formatFlatFrame($index, $f, $includeArgs);
            if (null === $line) {
                continue;
            }
            echo $line;
            ++$index;
            ++$printed;
            if ($limit > 0 && $printed >= $limit) {
                break;
            }
        }
    }

    /**
     * Build a trace from an explicit frame list (issue #6470 fiber suspend stacks).
     *
     * @param list<Frame> $frames innermost first
     */
    public static function buildFromFrames(
        array $frames,
        int $options = 0,
        bool $includeHandlers = false,
    ): Variable {
        $includeArgs = 0 === ($options & self::IGNORE_ARGS);
        $provideObject = 0 !== ($options & self::PROVIDE_OBJECT);

        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();

        foreach ($frames as $f) {
            if (!$includeHandlers && $f->hasHandler()) {
                continue;
            }
            $entry = self::frameEntry($f, $includeArgs, $provideObject, $includeHandlers);
            if (null === $entry) {
                continue;
            }
            $ht->append($entry);
        }

        return $result;
    }

    /**
     * Zend returns an empty trace when debug_backtrace() is invoked from file-level {main}
     * (skip_last leaves no user frames). See zend_fetch_debug_backtrace() / #10484.
     */
    private static function isTopLevelBacktraceCall(Frame $frame): bool
    {
        $caller = self::callerFrame($frame);
        if (null === $caller) {
            return true;
        }

        return null !== $caller->block && $caller->block->isMainScript();
    }

    private static function callerFrame(Frame $frame): ?Frame
    {
        if ($frame->hasHandler() && null !== $frame->parent) {
            return $frame->parent;
        }

        return $frame->parent;
    }

    /**
     * @return list<Frame>
     */
    private static function collectFrames(Frame $frame): array
    {
        $walk = [];
        if ($frame->hasHandler() && null !== $frame->parent) {
            $walk[] = $frame->parent;
        }
        if (null !== $frame->vmContext) {
            foreach ($frame->vmContext->runStackFrames() as $stackFrame) {
                $walk[] = $stackFrame;
            }
        }
        if ([] === $walk) {
            $walk = self::parentChainFrames($frame);
        }

        return $walk;
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

    private static function frameEntry(
        Frame $frame,
        bool $includeArgs,
        bool $provideObject,
        bool $includeHandlerMetadata,
    ): ?Variable {
        $function = self::frameFunction($frame);
        $file = self::frameFile($frame);
        if ('' === $function && '' === $file) {
            return null;
        }

        $entry = new Variable();
        $entry->newArray();
        $ht = $entry->toArray();

        if ('' !== $file) {
            $fileVar = new Variable(Variable::TYPE_STRING);
            $fileVar->string($file);
            $ht->add('file', $fileVar);

            $lineVar = new Variable(Variable::TYPE_INTEGER);
            $lineVar->int(self::frameLine($frame));
            $ht->add('line', $lineVar);
        }

        $fnVar = new Variable(Variable::TYPE_STRING);
        $fnVar->string($function);
        $ht->add('function', $fnVar);

        if ($includeHandlerMetadata && $frame->hasHandler()) {
            $className = self::handlerClassName($frame);
            if ('' !== $className) {
                $classVar = new Variable(Variable::TYPE_STRING);
                $classVar->string($className);
                $ht->add('class', $classVar);

                $typeVar = new Variable(Variable::TYPE_STRING);
                $typeVar->string('::');
                $ht->add('type', $typeVar);
            }
        }

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
        if ('' === $name && null === $func->class) {
            return '{closure}';
        }
        if (null !== $func->class) {
            $class = $func->class->value ?? $func->class->name ?? '';

            return '' !== $class ? $class.'::'.$name : $name;
        }

        return $name;
    }

    private static function handlerClassName(Frame $frame): string
    {
        if (!$frame->hasHandler()) {
            return '';
        }
        $handlerClass = $frame->handler::class;
        if (str_starts_with($handlerClass, 'PHPCompiler\\VM\\Builtin\\Fiber')) {
            return 'Fiber';
        }

        return '';
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

    private static function formatFlatFrame(int $index, Frame $frame, bool $includeArgs): ?string
    {
        $function = self::frameFunctionName($frame);
        $file = self::frameFile($frame);
        if ('{main}' === $function || ('' === $function && '' === $file)) {
            return null;
        }

        $line = '#'.$index;
        if ('' !== $file) {
            $line .= ' '.$file.'('.self::frameLine($frame).'):';
        }
        $line .= ' ';
        $class = self::frameClass($frame);
        if ('' !== $class) {
            $line .= $class.self::frameCallType($frame);
        }
        $line .= $function;
        if ($includeArgs) {
            $parts = [];
            $args = SensitiveParamSupport::buildArgsArray($frame);
            if (null !== $args) {
                foreach ($args->toArray()->iterateKeyed(false) as [, $arg]) {
                    $parts[] = self::formatFlatTraceArg($arg);
                }
            }
            $line .= '('.implode(', ', $parts).')';
        } else {
            $line .= '()';
        }

        return $line."\n";
    }

    private static function frameFunctionName(Frame $frame): string
    {
        if ($frame->hasHandler()) {
            return $frame->handler->getName();
        }
        if (null === $frame->block || null === $frame->block->func) {
            return '';
        }
        $func = $frame->block->func;
        $name = $func->name;
        if ('' === $name && null === $func->class) {
            return '{main}';
        }

        return $name;
    }

    private static function frameClass(Frame $frame): string
    {
        if (null === $frame->block || null === $frame->block->func || null === $frame->block->func->class) {
            return '';
        }
        $func = $frame->block->func;

        return $func->class->value ?? $func->class->name ?? '';
    }

    private static function frameCallType(Frame $frame): string
    {
        if (null === $frame->block || null === $frame->block->func || null === $frame->block->func->class) {
            return '';
        }
        if (($frame->block->func->flags ?? 0) & Func::FLAG_STATIC) {
            return '::';
        }

        return '->';
    }

    /** zend_print_flat_zval() argument formatting (ext/standard/var.c). */
    private static function formatFlatTraceArg(Variable $arg): string
    {
        if (SensitiveParamSupport::isMarker($arg)) {
            return SensitiveParamSupport::TRACE_ARG_LABEL;
        }
        $arg = $arg->resolveIndirect();
        switch ($arg->type) {
            case Variable::TYPE_STRING:
                return "'...'";
            case Variable::TYPE_NULL:
                return 'NULL';
            case Variable::TYPE_BOOLEAN:
                return $arg->toBool() ? 'true' : 'false';
            case Variable::TYPE_INTEGER:
                return (string) $arg->toInt();
            case Variable::TYPE_FLOAT:
                return (string) $arg->toFloat();
            case Variable::TYPE_OBJECT:
                return 'Object('.($arg->toObject()->class->name).')';
            case Variable::TYPE_ARRAY:
                return 'Array';
            default:
                return $arg->toString();
        }
    }
}
