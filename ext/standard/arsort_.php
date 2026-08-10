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
 * arsort() — sort by value descending, preserve keys (subset of PHP; issue #2296, #4118, #11991).
 *
 * VM: key-preserving value sort via {@see VmArray::arsortCopy()}.
 * JIT/AOT: packed list via SortJitHelper reverse; string-key via __hashtable__sortStringKeyValuesReverse.
 */
final class arsort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('arsort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        VmArraySort::assertFlagSortArgCount($argc, 'arsort');
        $array = $frame->calledArgs[0]->resolveIndirect();
        $ht = VmArray::requireArray($frame->calledArgs[0], 'arsort');
        $flags = StdlibConstants::SORT_REGULAR;
        if (2 === $argc) {
            $flags = VmInternalCompare::resolveFrameSortFlags($frame, 'arsort');
        }
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $array->array(VmArray::arsortCopy($ht, $flags));
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
                    ? \sprintf('arsort() expects at least 1 argument, %d given', $argc)
                    : \sprintf('arsort() expects at most 2 arguments, %d given', $argc)
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'arsort');
        if (1 === $argc) {
            ValueSortRuntime::arsortByValue($context, $args[0]);
        } else {
            self::jitSortByValueWithFlags(
                $context,
                $args[0],
                VmInternalCompare::resolveJitSortFlags($context, $args[1], 'arsort')
            );
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function jitSortByValueWithFlags(Context $context, JITVariable $array, int $flags): void
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (
            StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType
            || StdlibConstants::SORT_STRING === $sortType
            || StdlibConstants::SORT_LOCALE_STRING === $sortType
        ) {
            ValueSortRuntime::arsortByValue($context, $array);

            return;
        }
        if (StdlibConstants::SORT_NATURAL === $sortType) {
            throw new \LogicException('arsort() flags are not supported in JIT/AOT in this compiler build');
        }
        ValueSortRuntime::arsortByValue($context, $array);
    }
}
