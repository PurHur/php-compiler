<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
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
        // Static — no implicit $this (php-src zim_Closure_fromCallable, #30930).
        $argc = \count($args);
        if (1 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage('Closure::fromCallable', 1, $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'closure_fromcallable_argc_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
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
