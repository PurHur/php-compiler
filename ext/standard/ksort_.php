<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\KeySortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ksort() — sort by key preserving values (subset of PHP; issue #2271, #4118).
 *
 * VM: homogeneous string or integer keys; list-shaped int keys sort by key (#10836).
 * JIT/AOT: all operands via KeySortJitHelper PHP bridge (#12770, #18381).
 */
final class ksort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ksort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        VmArraySort::assertFlagSortArgCount($argc, 'ksort');
        $array = $frame->calledArgs[0]->resolveIndirect();
        $ht = VmArray::requireArray($frame->calledArgs[0], 'ksort');
        $flags = StdlibConstants::SORT_REGULAR;
        if (2 === $argc) {
            $flags = VmInternalCompare::resolveFrameSortFlags($frame, 'ksort');
        }
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $array->array(VmArray::ksortCopy($ht, $flags));
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
                    ? \sprintf('ksort() expects at least 1 argument, %d given', $argc)
                    : \sprintf('ksort() expects at most 2 arguments, %d given', $argc)
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'ksort');
        if (1 === $argc) {
            KeySortRuntime::ksortByKey($context, $args[0]);
        } else {
            self::jitSortByKeyWithFlags(
                $context,
                $args[0],
                VmInternalCompare::resolveJitSortFlags($context, $args[1], 'ksort')
            );
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function jitSortByKeyWithFlags(Context $context, JITVariable $array, int $flags): void
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            KeySortRuntime::ksortByKeyLocale($context, $array);

            return;
        }
        if (
            StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_STRING === $sortType
        ) {
            KeySortRuntime::ksortByKey($context, $array);

            return;
        }
        if (StdlibConstants::SORT_NUMERIC === $sortType || StdlibConstants::SORT_NATURAL === $sortType) {
            throw new \LogicException('ksort() flags are not supported in JIT/AOT in this compiler build');
        }
        KeySortRuntime::ksortByKey($context, $array);
    }
}
