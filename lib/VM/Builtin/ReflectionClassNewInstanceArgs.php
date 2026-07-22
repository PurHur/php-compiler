<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::newInstanceArgs(array $args) — VM (#22086, ext/reflection/php_reflection.c). */
final class ReflectionClassNewInstanceArgs extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newInstanceArgs');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'ReflectionClass::newInstanceArgs() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        $ctorArgs = ReflectionSupport::invokeArgsFromArray(
            $frame->calledArgs[1],
            'ReflectionClass::newInstanceArgs'
        );
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionClass::newInstanceArgs() requires active VM');
        }
        $object = ReflectionSupport::instantiateReflectedClass($vm, $entry, $ctorArgs);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($object);
            $frame->returnVar->copyFrom($out);
        }
    }
}
