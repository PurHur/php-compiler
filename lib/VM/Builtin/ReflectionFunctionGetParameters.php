<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

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
        $func = ReflectionSupport::resolveUserFunction(
            $ctx,
            ReflectionSupport::functionNameFromReflection($receiver)
        );
        $paramClass = $ctx->classes[ReflectionSupport::REFLECTION_PARAMETER] ?? null;
        if (null === $paramClass) {
            throw new \LogicException('ReflectionParameter is not registered in this compiler build');
        }
        $funcName = ReflectionSupport::functionNameFromReflection($receiver);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        $indices = array_keys($func->block->paramNames);
        sort($indices, SORT_NUMERIC);
        foreach ($indices as $index) {
            $param = new ObjectEntry($paramClass);
            $param->constructed = true;
            $param->getProperty(ReflectionSupport::PROP_FUNC_NAME)->string($funcName);
            $param->getProperty(ReflectionSupport::PROP_PARAM_INDEX)->int((int) $index);
            $param->getProperty(ReflectionSupport::PROP_PARAM_NAME)->string($func->block->paramNames[$index]);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($param);
            $ht->append($slot);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
