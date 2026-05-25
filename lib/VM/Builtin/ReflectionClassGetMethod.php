<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getMethod() — VM (#1936). */
final class ReflectionClassGetMethod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMethod');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::getMethod() expects a method name');
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $method = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::getMethod() name');
        $methodLc = strtolower($method);
        if (!isset($entry->methods[$methodLc])) {
            throw new \LogicException("Method {$method} does not exist on {$className}");
        }
        $rmClass = $ctx->classes[ReflectionSupport::REFLECTION_METHOD] ?? null;
        if (null === $rmClass) {
            throw new \LogicException('ReflectionMethod is not registered in this compiler build');
        }
        $rm = new ObjectEntry($rmClass);
        $rm->constructed = true;
        $rm->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($entry->name);
        $rm->getProperty(ReflectionSupport::PROP_METHOD_NAME)->string($method);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($rm);
            $frame->returnVar->copyFrom($out);
        }
    }
}
