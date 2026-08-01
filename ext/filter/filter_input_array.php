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

/** filter_input_array() — batch filter from INPUT_* superglobals (php-src ext/filter/filter.c; #3294, #21937). */
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
            $definition = self::resolveDefinition($frame->calledArgs[1]);
        }
        // php-src Z_PARAM_ARRAY_HT_OR_LONG — unknown int filter ID fails before empty-source NULL (#23369).
        if (\is_int($definition) && !VmFilter::isSupportedFilter($definition)) {
            filter_var::triggerUnknownFilterWarning($frame, $definition, 'filter_input_array');
            $frame->returnVar->bool(false);

            return;
        }
        // php-src stub default add_empty=true (ext/filter/filter.stub.php; #26201).
        $addEmpty = 1;
        if (3 === $argc) {
            $addEmpty = InternalStrictArg::requireBuiltinTypedBoolArg(
                $frame->calledArgs[2],
                'filter_input_array',
                2,
                'add_empty'
            ) ? 1 : 0;
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
        $addEmpty = 1;
        if (3 === $argc && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            JitInternalStrictArg::requireBool($context, $args[2], 'filter_input_array', 'add_empty', 3);
            $addEmpty = self::compileTimeBoolAsInt($context, $args[2]) ?? 1;
        }

        return FilterInputArrayRuntime::filter($context, $args[0], $args[1], $addEmpty);
    }

    /**
     * php-src Z_PARAM_ARRAY_HT_OR_LONG — array, or scalar coerced to filter ID (bool/float → int; #23369).
     *
     * @return \PHPCompiler\VM\HashTable|int|null
     */
    private static function resolveDefinition(Variable $arg): \PHPCompiler\VM\HashTable|int|null
    {
        $defArg = $arg->resolveIndirect();
        if ($defArg->isUndefined() || Variable::TYPE_NULL === $defArg->type) {
            return null;
        }
        if (Variable::TYPE_INTEGER === $defArg->type
            || Variable::TYPE_BOOLEAN === $defArg->type
            || Variable::TYPE_FLOAT === $defArg->type) {
            return $defArg->toInt();
        }
        if (Variable::TYPE_ARRAY === $defArg->type) {
            return $defArg->toArray();
        }
        VmArray::requireArrayParam($arg, 'filter_input_array', 2, 'options', 'array|int|null');

        return null;
    }

    /** Compile-time bool → 0/1 for FilterInputArrayRuntime add_empty (#26201). */
    private static function compileTimeBoolAsInt(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null === $lib->LLVMIsAConstantInt($var->value->value)) {
                return null;
            }

            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value) ? 1 : 0;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($var->value->value)) {
            return null;
        }

        return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value) ? 1 : 0;
    }
}
