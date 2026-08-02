<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\VmFromCallable;
use PHPLLVM\Value;

/**
 * Closure::fromCallable() — JIT/AOT (#26788, Zend/zend_closures.c).
 *
 * Compile-time string / Class::method callables reuse {@see VmFromCallable} (same as FCC).
 */
final class ClosureFromCallable implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Closure::fromCallable';

    /** @var list<string> php-src Zend/zend_closures.stub.php */
    public array $paramNames = ['callback'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('Closure::fromCallable() expects exactly 1 argument, 0 given');
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $name || '' === $name) {
            throw new \LogicException(
                'Closure::fromCallable() requires a compile-time string callable in this compiler build (#26788)'
            );
        }
        $closure = VmFromCallable::fromCallableString($context, $name, new Block());

        return ClosureBindHelper::boxReturn($context, $closure);
    }
}
