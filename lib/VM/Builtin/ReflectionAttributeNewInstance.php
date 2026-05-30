<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionAttribute::newInstance() — VM (#3206, #3800). */
final class ReflectionAttributeNewInstance extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newInstance');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_ATTR_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionAttribute missing name');
        }
        $className = $nameVar->toString();
        $lc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            throw new \LogicException('Attribute class "'.$className.'" not found');
        }
        $classEntry = $ctx->classes[$lc];
        $object = new ObjectEntry($classEntry);
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        $callArgs = [$thisVar];
        foreach (ReflectionSupport::argsFromReflectionObject($receiver) as $spec) {
            if (null !== $spec['name']) {
                continue;
            }
            $callArgs[] = ReflectionSupport::scalarToVariable($spec['value']);
        }
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionAttribute::newInstance() requires active VM');
        }
        if (null !== $classEntry->constructor) {
            $vm->invokePhpFunction($classEntry->constructor, ...$callArgs);
        } else {
            $object->constructed = true;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($object);
        }
    }
}
