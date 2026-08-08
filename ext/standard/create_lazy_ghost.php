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
 * createLazyGhost() — historical procedural lazy ghost factory (#6708).
 *
 * Never registered: php-src exposes ReflectionClass::newLazyGhost only (#28414).
 * File kept so ReflectionClass / JIT helpers can reuse {@see JitCreateLazyGhost}.
 *
 * @see Zend/zend_lazy_objects.c
 * @see Zend/zend_builtin_functions.c
 */
final class create_lazy_ghost extends Internal
{
    private const NAME = 'createLazyGhost';

    public function __construct()
    {
        parent::__construct(self::NAME);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                self::NAME.'() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmString::coerceStringBuiltinArg($frame->calledArgs[0], self::NAME, 0, 'class');
        $entry = LazyObjectSupport::resolveClassForLazyFactory($ctx, $className, self::NAME);
        $initializerClosure = LazyObjectSupport::extractRequiredCallableObject(
            $frame->calledArgs[1],
            self::NAME,
            2,
            'initializer'
        );
        if (3 === $argc) {
            $optionsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_LONG !== $optionsVar->type) {
                throw new \TypeError(
                    self::NAME.'(): Argument #3 ($options) must be of type int, '
                    .EnumCaseSupport::typeNameForVariable($optionsVar).' given'
                );
            }
        }
        $lazy = LazyObjectSupport::createGhost(
            $entry,
            $initializerClosure->closureState,
            0,
            $initializerClosure
        );
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($lazy);
            $frame->returnVar->copyFrom($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCreateLazyGhost::invoke($context, ...$args);
    }
}
