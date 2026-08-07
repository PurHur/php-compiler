<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * class_has_lazy_object_initializer() — historical free-function lazy ghost probe (#6052).
 *
 * Never registered: php-src introspects via ReflectionClass::isUninitializedLazyObject /
 * getLazyInitializer only (#28517). File kept for inventory/spine + internal LazyObjectSupport.
 *
 * @see Zend/zend_lazy_objects.c
 */
final class class_has_lazy_object_initializer extends Internal
{
    private const NAME = 'class_has_lazy_object_initializer';

    public function __construct()
    {
        parent::__construct(self::NAME);
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                self::NAME.'() expects exactly 1 argument, 0 given'
            );
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(
                self::NAME.'(): Argument #1 ($object) must be of type object, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                LazyObjectSupport::hasLazyObjectInitializer($arg->toObject())
            );
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(self::NAME.'() is VM-only in this compiler build');
    }
}
