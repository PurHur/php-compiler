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

/** ReflectionParameter::getType() — JIT/AOT (#28780, ext/reflection/php_reflection.c). */
final class ReflectionParameterGetType implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionParameter::getType()',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        $recorded = ReflectionInternalFunctionLowering::recordedFunctions();
        if (1 === \count($recorded)) {
            $funcLc = (string) array_key_first($recorded);
            $names = \PHPCompiler\BuiltinParamNames::paramNamesForInternalFunction($funcLc);
            if (null === $names) {
                $names = \PHPCompiler\BuiltinParamNames::forFunction($funcLc);
            }
            if (1 === \count($names ?? [])) {
                $info = BuiltinInternalArgInfo::paramInfoForFunction($funcLc, 0);
                $label = null !== $info ? trim((string) ($info['type'] ?? '')) : '';
                if ('' !== $label) {
                    BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_param_type_fold');

                    return ReflectionTypeJitHelper::emitTypeFromLabelHeap($context, $label);
                }
            }
        }

        $labelPtr = self::paramTypeLabelCstr($context, $args[0]);

        return $context->builder->call(
            $context->lookupFunction('__compiler_refl_type_from_label_cstr'),
            $labelPtr
        );
    }

    public static function paramTypeLabelCstr(Context $context, Variable $receiverArg): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $receiverArg);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_FUNC_NAME
        );
        $index = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_INDEX
        );

        return $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_param_type_label_at'),
            $funcCstr,
            $index
        );
    }
}
