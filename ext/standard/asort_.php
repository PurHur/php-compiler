<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ValueSortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * asort() — sort by value ascending, preserve keys (subset of PHP; issue #2290, #4118, #11991).
 *
 * VM: key-preserving value sort via {@see VmArray::asortCopy()}.
 * JIT/AOT: packed list via SortJitHelper; string-key via __hashtable__sortStringKeyValues.
 */
final class asort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('asort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        VmArraySort::assertFlagSortArgCount($argc, 'asort');
        $array = $frame->calledArgs[0]->resolveIndirect();
        $ht = VmArray::requireArray($frame->calledArgs[0], 'asort');
        $flags = StdlibConstants::SORT_REGULAR;
        if (2 === $argc) {
            $flags = VmInternalCompare::resolveFrameSortFlags($frame, 'asort');
        }
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $array->array(VmArray::asortCopy($ht, $flags));
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('asort() expects at least 1 argument, %d given', $argc)
                    : \sprintf('asort() expects at most 2 arguments, %d given', $argc)
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'asort');
        if (1 === $argc) {
            ValueSortRuntime::asortByValue($context, $args[0]);
        } else {
            self::jitSortByValueWithFlags(
                $context,
                $args[0],
                VmInternalCompare::resolveJitSortFlags($context, $args[1], 'asort')
            );
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function jitSortByValueWithFlags(Context $context, JITVariable $array, int $flags): void
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            ValueSortRuntime::asortByValueLocale($context, $array);

            return;
        }
        if (
            StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType
            || StdlibConstants::SORT_STRING === $sortType
        ) {
            ValueSortRuntime::asortByValue($context, $array);

            return;
        }
        if (StdlibConstants::SORT_NATURAL === $sortType) {
            throw new \LogicException('asort() flags are not supported in JIT/AOT in this compiler build');
        }
        ValueSortRuntime::asortByValue($context, $array);
    }
}
