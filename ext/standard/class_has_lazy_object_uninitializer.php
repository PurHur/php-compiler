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
 * class_has_lazy_object_uninitializer() — PHP 8.4 lazy proxy probe (#6097).
 *
 * @see Zend/zend_lazy_objects.c — proxy pending factory flag
 * @see ext/standard/basic_functions.c — PHP_FUNCTION(class_has_lazy_object_uninitializer)
 */
final class class_has_lazy_object_uninitializer extends Internal
{
    private const NAME = 'class_has_lazy_object_uninitializer';

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
                LazyObjectSupport::hasLazyObjectUninitializer($arg->toObject())
            );
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(self::NAME.'() is VM-only in this compiler build');
    }
}
