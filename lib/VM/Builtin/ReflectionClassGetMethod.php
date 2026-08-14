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
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (1 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::getMethod', 1);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        if (null === VmReflection::resolveClassEntry($ctx, $className)) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $method = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::getMethod() name', 1);
        [$declEntry, $canonical] = ReflectionSupport::reflectionMethodFromClassAndMethod(
            $ctx,
            $className,
            $method
        );
        $rmClass = $ctx->classes[ReflectionSupport::REFLECTION_METHOD] ?? null;
        if (null === $rmClass) {
            throw new \LogicException('ReflectionMethod is not registered in this compiler build');
        }
        $rm = new ObjectEntry($rmClass);
        $rm->constructed = true;
        // php-src: declaring scope ce on ReflectionMethod::$class (#22582).
        $rm->getProperty(ReflectionSupport::PROP_REFLECTION_METHOD_CLASS)->string($declEntry->name);
        $rm->getProperty(ReflectionSupport::PROP_REFLECTION_METHOD_FUNC)->string($canonical);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($rm);
            $frame->returnVar->copyFrom($out);
        }
    }
}
