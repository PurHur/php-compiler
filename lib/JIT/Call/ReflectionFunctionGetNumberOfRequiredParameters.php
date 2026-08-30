<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionInternalFunctionLowering;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionFunction::getNumberOfRequiredParameters() — JIT/AOT (#25469 / #25559). */
final class ReflectionFunctionGetNumberOfRequiredParameters implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            VmReflection::functionAbstractReceiverOnlyDisplayName('getNumberOfRequiredParameters'),
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        $recorded = ReflectionInternalFunctionLowering::recordedFunctions();
        if (1 === \count($recorded)) {
            $funcLc = (string) array_key_first($recorded);
            $required = BuiltinParamNames::requiredParamCountForInternalFunction($funcLc) ?? 0;
            BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_func_required_count_fold');
            $resultSlot = JitValueBox::alloc($context);
            JitValueBox::writeLong(
                $context,
                $resultSlot,
                $context->getTypeFromString('int64')->constInt(max(0, $required), false)
            );

            return $resultSlot;
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionFunction',
            ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME
        );
        $total = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_param_count'),
            $funcCstr
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $resultSlot, $context->builder->zExt($total, $context->getTypeFromString('int64')));

        return $resultSlot;
    }
}
