<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::getDeclaringClass() — VM (#14913, #15658, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetDeclaringClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeclaringClass');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionMethod_getDeclaringClass — ZEND_PARSE_PARAMETERS (0 args) (#31127)
        $this->requireExactUserArgCount($frame, 'ReflectionMethod::getDeclaringClass', 0);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $declaringName = ReflectionSupport::declaringClassNameFromReflectionMethod($receiver, $ctx);
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(ReflectionSupport::newReflectionClassObjectForName($ctx, $declaringName));
            $frame->returnVar->copyFrom($out);
        }
    }
}
