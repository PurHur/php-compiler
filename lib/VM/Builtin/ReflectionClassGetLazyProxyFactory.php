<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getLazyProxyFactory() — VM (#6776, #29152, ext/reflection/php_reflection.c). */
final class ReflectionClassGetLazyProxyFactory extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLazyProxyFactory');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::getLazyProxyFactory() expects an object');
        }
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError('ReflectionClass::getLazyProxyFactory(): Argument #1 ($object) must be of type object');
        }
        $object = $objectVar->toObject();
        $closure = LazyObjectSupport::getProxyFactoryClosure($object);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $closure) {
            $factory = LazyObjectSupport::getProxyFactory($object);
            if (null === $factory) {
                $frame->returnVar->null();

                return;
            }
            $ctx = $frame->vmContext;
            if (null === $ctx) {
                throw new \LogicException('ReflectionClass::getLazyProxyFactory() requires VM context');
            }
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(ClosureSupport::wrapState($ctx, $factory));
            $frame->returnVar->copyFrom($out);

            return;
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object($closure);
        $frame->returnVar->copyFrom($out);
    }
}
