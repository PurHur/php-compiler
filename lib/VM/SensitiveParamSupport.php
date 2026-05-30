<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCfg\Func;

/**
 * #[\SensitiveParameter] trace redaction (PHP 8.2, Zend zend_builtin_functions.c, issue #3351).
 */
final class SensitiveParamSupport
{
    public const CLASS_NAME = 'SensitiveParameterValue';

    public const TRACE_ARG_LABEL = '[Sensitive Parameter]';

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry(self::CLASS_NAME);
        $ctx->classes[strtolower(self::CLASS_NAME)] = $entry;
    }

    public static function createMarker(): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object(new ObjectEntry(self::markerClassEntry()));

        return $var;
    }

    public static function isMarker(Variable $value): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }

        return strtolower($value->toObject()->class->name) === strtolower(self::CLASS_NAME);
    }

    /**
     * Packed list of call arguments for debug_backtrace / getTrace frames.
     */
    public static function buildArgsArray(Frame $frame): ?Variable
    {
        if ([] === $frame->calledArgs || null === $frame->block) {
            return null;
        }

        $sensitive = $frame->block->paramSensitive;
        if ([] === $sensitive) {
            return self::copyArgsList($frame);
        }

        $thisOffset = self::calledArgThisOffset($frame);
        $out = new Variable();
        $out->newArray();
        $ht = $out->toArray();
        $paramCount = count($frame->block->paramNames);
        for ($paramIdx = 0; $paramIdx < $paramCount; ++$paramIdx) {
            $argIdx = $thisOffset + $paramIdx;
            if (!array_key_exists($argIdx, $frame->calledArgs)) {
                continue;
            }
            if (isset($sensitive[$paramIdx])) {
                $ht->append(self::createMarker());

                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$argIdx]->resolveIndirect());
            $ht->append($copy);
        }

        return $out;
    }

    /** Format one trace argument for getTraceAsString()-style output. */
    public static function formatTraceArg(Variable $arg): string
    {
        if (self::isMarker($arg)) {
            return self::TRACE_ARG_LABEL;
        }
        $arg = $arg->resolveIndirect();
        switch ($arg->type) {
            case Variable::TYPE_STRING:
                return '"'.$arg->toString().'"';
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

    private static function copyArgsList(Frame $frame): Variable
    {
        $out = new Variable();
        $out->newArray();
        $ht = $out->toArray();
        foreach ($frame->calledArgs as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg->resolveIndirect());
            $ht->append($copy);
        }

        return $out;
    }

    private static function calledArgThisOffset(Frame $frame): int
    {
        $func = $frame->block->func ?? null;
        if (null === $func || null === $func->class) {
            return 0;
        }
        if (($func->flags ?? 0) & Func::FLAG_STATIC) {
            return 0;
        }

        return 1;
    }

    private static function markerClassEntry(): ClassEntry
    {
        static $entry = null;
        if (null === $entry) {
            $entry = new ClassEntry(self::CLASS_NAME);
        }

        return $entry;
    }
}
