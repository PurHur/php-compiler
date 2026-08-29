<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionInternalFunctionLowering;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\ReflectionTypeJitHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionFunction::getReturnType() — JIT/AOT (#28780). */
final class ReflectionFunctionGetReturnType implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionFunction::getReturnType()',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        // Literal `ReflectionFunction('…')` on a single recorded internal: fold (#28780).
        $recorded = ReflectionInternalFunctionLowering::recordedFunctions();
        if (1 === \count($recorded)) {
            $funcLc = (string) array_key_first($recorded);
            $label = BuiltinInternalArgInfo::returnTypeLabelForFunction($funcLc);
            if (null !== $label && '' !== $label) {
                BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_func_return_type_fold');
                $boxed = ReflectionTypeJitHelper::emitTypeFromLabelHeap($context, $label);
                BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_func_return_type_fold_done');

                return $boxed;
            }
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionFunction',
            ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME
        );
        $labelPtr = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_return_type_label'),
            $funcCstr
        );
        $boxed = $context->builder->call(
            $context->lookupFunction('__compiler_refl_type_from_label_cstr'),
            $labelPtr
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_func_return_type_rt_done');

        return $boxed;
    }
}