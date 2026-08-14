<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getParentClass() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassGetParentClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getParentClass');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::getParentClass', 0);
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        $parentName = VmReflection::parentClassName($entry, $ctx);
        if (null === $parentName) {
            $frame->returnVar->bool(false);

            return;
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(ReflectionSupport::newReflectionClassObjectForName($ctx, $parentName));
        $frame->returnVar->copyFrom($out);
    }
}
