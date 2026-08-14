<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::getPrototype() — VM (#7262, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetPrototype extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPrototype');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionMethod_getPrototype — ZEND_PARSE_PARAMETERS (0 args) (#31127)
        $this->requireExactUserArgCount($frame, 'ReflectionMethod::getPrototype', 0);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $methodName = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($methodName);
        $proto = ReflectionSupport::methodPrototypeClassEntry($ctx, $entry, $methodLc);
        if (null === $proto) {
            ReflectionSupport::throwReflectionException(
                sprintf('Method %s::%s does not have a prototype', $className, $methodName)
            );
        }
        $canonicalMethod = $proto->methodNames[$methodLc] ?? $methodName;
        $rm = ReflectionSupport::newReflectionMethodObject($ctx, $proto, $canonicalMethod);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($rm);
            $frame->returnVar->copyFrom($out);
        }
    }
}
