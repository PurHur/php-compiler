<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFunction::getParameters() — VM (#3355). */
final class ReflectionFunctionGetParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getParameters');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $funcName = ReflectionSupport::functionNameFromReflection($receiver);
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $paramNames = BuiltinParamNames::paramNamesForInternalFunction($funcName) ?? [];
        } else {
            $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
            $paramNames = $func->block->paramNames;
        }
        $closureState = $receiver->reflectionClosureState;
        $paramClass = $ctx->classes[ReflectionSupport::REFLECTION_PARAMETER] ?? null;
        if (null === $paramClass) {
            throw new \LogicException('ReflectionParameter is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (array_keys($paramNames) as $index) {
            $param = new ObjectEntry($paramClass);
            $param->constructed = true;
            $param->getProperty(ReflectionSupport::PROP_FUNC_NAME)->string($funcName);
            $param->getProperty(ReflectionSupport::PROP_PARAM_CLASS)->null();
            $param->getProperty(ReflectionSupport::PROP_METHOD_NAME)->null();
            $param->getProperty(ReflectionSupport::PROP_PARAM_INDEX)->int((int) $index);
            $param->getProperty(ReflectionSupport::PROP_PARAM_POSITION)->int((int) $index);
            // Strip InternalArgInfo / BuiltinParamNames optionality markers for Reflection::$name (#23608).
            $displayName = ltrim((string) $paramNames[$index], '&');
            if (str_starts_with($displayName, '...')) {
                $displayName = substr($displayName, 3);
            }
            $displayName = rtrim($displayName, '=');
            $param->getProperty(ReflectionSupport::PROP_PARAM_NAME)->string($displayName);
            if (null !== $closureState) {
                $param->reflectionClosureState = $closureState;
            }
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($param);
            $ht->append($slot);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
