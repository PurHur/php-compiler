<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_declared_attributes() — list user attribute classes (#6450).
 *
 * Phantom vs php-src: never registered in ext/reflection/php_reflection.c (#24222).
 * Kept unregistered under {@see \PHPCompiler\CompilerVersion::advertisesGetDeclaredAttributes()}.
 */
final class get_declared_attributes_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_declared_attributes');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('get_declared_attributes() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmReflection::declaredAttributesTable(VmReflection::requireContext($frame))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'get_declared_attributes() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGetDeclaredAttributes::invoke($context);
    }
}
