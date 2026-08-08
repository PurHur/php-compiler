<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionClass::newLazyGhost(callable $initializer, int $options = 0) — VM instance ABI.
 *
 * php-src: ext/reflection/php_reflection.c / Zend/zend_lazy_objects.c (#4026, #6399, #22527).
 * Not static: FLAG_STATIC dropped $this on `$rc->newLazyGhost($fn)` (#22288).
 */
final class ReflectionClassNewLazyGhost extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newLazyGhost');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ReflectionClass::newLazyGhost() expects at least 1 argument, 0 given'
            );
        }
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            throw new \LogicException('Cannot create lazy ghost of '.$entry->name);
        }
        $initializerClosure = LazyObjectSupport::extractRequiredCallableObject(
            $frame->calledArgs[1],
            'ReflectionClass::newLazyGhost',
            1,
            'initializer'
        );
        $options = 0;
        if (\count($frame->calledArgs) >= 3) {
            $options = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        $lazy = LazyObjectSupport::createGhost(
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
