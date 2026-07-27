<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\FilterVarArrayRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** filter_var_array() — batch filter_var() over an array (php-src ext/filter/filter.c; #3294, #21937). */
final class filter_var_array extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('filter_var_array() requires one to three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dataHt = VmArray::requireArrayParam(
            $frame->calledArgs[0],
            'filter_var_array',
            1,
            'array'
        );
        $definition = null;
        if ($argc >= 2) {
            $definition = self::resolveDefinition($frame->calledArgs[1]);
        }
        $addEmpty = 0;
        if (3 === $argc) {
            $addEmpty = InternalStrictArg::requireBuiltinTypedInt($frame, 2, 'filter_var_array', 'add_empty')->toInt();
        }
        $result = VmFilter::filterVarArray($dataHt, $definition, $addEmpty, $frame);
        if (null === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('filter_var_array() requires two or three arguments in this compiler build');
        }
        $addEmpty = 0;
        if (3 === $argc && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            JitInternalStrictArg::requireInt($context, $args[2], 'filter_var_array', 'add_empty', 3);
            $addEmpty = self::compileTimeInt($context, $args[2]) ?? 0;
        }

        return FilterVarArrayRuntime::filter($context, $args[0], $args[1], $addEmpty);
    }

    /** @return \PHPCompiler\VM\HashTable|int|null */
    private static function resolveDefinition(Variable $arg): \PHPCompiler\VM\HashTable|int|null
    {
        $defArg = $arg->resolveIndirect();
        if ($defArg->isUndefined() || Variable::TYPE_NULL === $defArg->type) {
            return null;
        }
        if (Variable::TYPE_INTEGER === $defArg->type) {
            return $defArg->toInt();
        }
        if (Variable::TYPE_ARRAY === $defArg->type) {
            return $defArg->toArray();
        }

        // Wrong type — TypeError with php-src-shaped array|int|null expectation (#21937).
        VmArray::requireArrayParam($arg, 'filter_var_array', 2, 'options', 'array|int|null');

        return null;
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
