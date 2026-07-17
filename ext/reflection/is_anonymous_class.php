<?php

declare(strict_types=1);

namespace PHPCompiler\ext\reflection;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * isAnonymousClass() — PHP 8.4 global wrapper around ReflectionClass::isAnonymous() (#19969).
 *
 * php-src: ext/reflection/php_reflection.c — PHP_FUNCTION(is_anonymous_class)
 */
final class is_anonymous_class extends Internal
{
    public function __construct()
    {
        parent::__construct('isAnonymousClass');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'isAnonymousClass', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            ReflectionSupport::isAnonymousClassObject($frame->calledArgs[0], 'isAnonymousClass')
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('isAnonymousClass() requires exactly one argument');
        }

        return JitIsAnonymousClass::invoke($context, $args[0]);
    }
}
