<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::getParameters() — VM (#3340). */
final class ReflectionMethodGetParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getParameters');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $method = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($method);
        $paramNames = ReflectionSupport::methodParameterNames($entry, $method);
        $rpClass = $ctx->classes[ReflectionSupport::REFLECTION_PARAMETER] ?? null;
        if (null === $rpClass) {
            throw new \LogicException('ReflectionParameter is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($paramNames as $position => $name) {
            $rp = new ObjectEntry($rpClass);
            $rp->constructed = true;
            $rp->getProperty(ReflectionSupport::PROP_PARAM_CLASS)->string($entry->name);
            $rp->getProperty(ReflectionSupport::PROP_METHOD_NAME)->string($method);
            $rp->getProperty(ReflectionSupport::PROP_FUNC_NAME)->null();
            $rp->getProperty(ReflectionSupport::PROP_PARAM_NAME)->string($name);
            $rp->getProperty(ReflectionSupport::PROP_PARAM_POSITION)->int($position);
            $rp->getProperty(ReflectionSupport::PROP_PARAM_INDEX)->int($position);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($rp);
            $ht->append($slot);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
