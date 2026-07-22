<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::newInstance(mixed ...$args) — VM (#22086, ext/reflection/php_reflection.c). */
final class ReflectionClassNewInstance extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newInstance');
    }

    public function execute(Frame $frame): void
    {
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        $ctorArgs = [];
        $argc = \count($frame->calledArgs);
        for ($i = 1; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $ctorArgs[] = $copy;
        }
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionClass::newInstance() requires active VM');
        }
        $object = ReflectionSupport::instantiateReflectedClass($vm, $entry, $ctorArgs);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($object);
            $frame->returnVar->copyFrom($out);
        }
    }
}
