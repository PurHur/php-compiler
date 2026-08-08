<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionClass::newLazyProxy(callable $factory, int $options = 0) — VM instance ABI.
 *
 * php-src: ext/reflection/php_reflection.c / Zend/zend_lazy_objects.c (#3317, #6399, #22527).
 * Not static: FLAG_STATIC dropped $this on `$rc->newLazyProxy($fn)` (#22288).
 */
final class ReflectionClassNewLazyProxy extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newLazyProxy');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ReflectionClass::newLazyProxy() expects at least 1 argument, 0 given'
            );
        }
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if ($entry->isTrait || $entry->isEnum) {
            throw new \LogicException('Cannot create lazy proxy of '.$entry->name);
        }
        $initializerClosure = LazyObjectSupport::extractRequiredCallableObject(
            $frame->calledArgs[1],
            'ReflectionClass::newLazyProxy',
            1,
            'factory'
        );
        $options = 0;
        if (\count($frame->calledArgs) >= 3) {
            $options = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        $lazy = LazyObjectSupport::createProxy(
            $entry,
            $initializerClosure->closureState,
            $options,
            $initializerClosure
        );
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($lazy);
            $frame->returnVar->copyFrom($out);
        }
    }
}
