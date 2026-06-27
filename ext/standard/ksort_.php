<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\KeySortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ksort() — sort by key preserving values (subset of PHP; issue #2271, #4118).
 *
 * VM: homogeneous string or integer keys; list-shaped int keys sort by key (#10836).
 * JIT/AOT: packed list no-op for ascending keys; string-key hashtable via __hashtable__sortStringKeys.
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
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ksort() requires one or two arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $ht = VmArray::requireArray($frame->calledArgs[0], 'ksort');
        $flags = StdlibConstants::SORT_REGULAR;
        if (2 === $argc) {
            $flags = VmInternalCompare::resolveFrameSortFlags($frame, 'ksort');
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
            throw new \LogicException('ksort() requires one or two arguments');
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'ksort');
        if (1 === $argc) {
            KeySortRuntime::ksortByKey($context, $args[0]);
        } else {
            self::jitSortByKeyWithFlags($context, $args[0], self::resolveJitSortFlags($context, $args[1]));
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function resolveJitSortFlags(Context $context, JITVariable $flagsArg): int
    {
        if (null !== $flagsArg->compileTimeConstantName) {
            $phpVar = $context->runtime->vmContext->constantFetch($flagsArg->compileTimeConstantName);
            if (null !== $phpVar && Variable::TYPE_INTEGER === $phpVar->type) {
                return $phpVar->toInt();
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG === $flagsArg->type) {
            throw new \LogicException(
                'ksort() flags must be a predefined constant in JIT/AOT in this compiler build'
            );
        }
        throw new \LogicException('ksort() flags must be an integer in this compiler build');
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
