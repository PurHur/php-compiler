<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::newInstanceWithoutConstructor() — VM (#5443, ext/reflection/php_reflection.c). */
final class ReflectionClassNewInstanceWithoutConstructor extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newInstanceWithoutConstructor');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_newInstanceWithoutConstructor — exact arity 0 (#30923)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::newInstanceWithoutConstructor', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('ReflectionClass::newInstanceWithoutConstructor() requires VM context');
        }
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        $object = $frame->vmContext->runtime->vm()->allocateObjectWithoutConstructor($entry);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($object);
            $frame->returnVar->copyFrom($out);
        }
    }
}
