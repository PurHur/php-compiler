<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getLazyInitializer() — VM (#5968, ext/reflection/php_reflection.c). */
final class ReflectionClassGetLazyInitializer extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLazyInitializer');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::getLazyInitializer() expects an object');
        }
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError('ReflectionClass::getLazyInitializer(): Argument #1 ($object) must be of type object');
        }
        $initializer = LazyObjectSupport::getInitializer($objectVar->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $initializer) {
            $frame->returnVar->null();

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ReflectionClass::getLazyInitializer() requires VM context');
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(ClosureSupport::wrapState($ctx, $initializer));
        $frame->returnVar->copyFrom($out);
    }
}
