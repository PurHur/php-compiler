<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\FilterInputArrayRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** filter_input_array() — batch filter from INPUT_* superglobals (php-src ext/filter/filter.c; #3294). */
final class filter_input_array extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('filter_input_array() requires one to three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('filter_input_array() requires VM context in this compiler build');
        }
        $typeInt = VmFilter::resolveInputType($frame->calledArgs[0], 'filter_input_array');
        $definition = null;
        if ($argc >= 2) {
            $defArg = $frame->calledArgs[1]->resolveIndirect();
            if (!$defArg->isUndefined() && Variable::TYPE_NULL !== $defArg->type) {
                $definition = VmArray::requireArrayParam(
                    $frame->calledArgs[1],
                    'filter_input_array',
                    2,
                    'definition',
                    '?array'
                );
            }
        }
        $addEmpty = 0;
        if (3 === $argc) {
            $addEmpty = InternalStrictArg::requireBuiltinTypedInt($frame, 2, 'filter_input_array', 'add_empty')->toInt();
        }
        $result = VmFilter::filterInputArray($ctx, $typeInt, $definition, $addEmpty, $frame);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('filter_input_array() requires two or three arguments in this compiler build');
        }
        if (!isset($args[1]) || NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            throw new \LogicException('filter_input_array() requires a definition array in this compiler build');
        }
        $addEmpty = 0;
        if (3 === $argc && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            JitInternalStrictArg::requireInt($context, $args[2], 'filter_input_array', 'add_empty', 3);
            $addEmpty = self::compileTimeInt($context, $args[2]) ?? 0;
        }

        return FilterInputArrayRuntime::filter($context, $args[0], $args[1], $addEmpty);
    }

    private static function compileTimeInt(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($var->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
    }
}
