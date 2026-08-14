<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** Closure::bindTo() instance method — JIT (#4192, #30867). */
final class ClosureBindTo implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: Zend/zend_closures.c — ZEND_PARSE_PARAMETERS(1, 2); $args[0] is $this (#30867)
        $userArgCount = max(0, \count($args) - 1);
        if ($userArgCount < 1 || $userArgCount > 2) {
            $message = $userArgCount < 1
                ? VmClassMethod::atLeastUserArgCountMessage('Closure::bindTo', 1, $userArgCount)
                : VmClassMethod::atMostUserArgCountMessage('Closure::bindTo', 2, $userArgCount);
            ExceptionBridge::emitArgumentCountErrorAndAbort($context, $message);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'closure_bindto_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $receiver = $args[0];
        $newThis = $args[1];
        $newScope = $args[2] ?? null;
        $result = ClosureBindHelper::bind(
            $context,
            $receiver,
            $newThis,
            $newScope,
            'Closure::bindTo()'
        );

        return ClosureBindHelper::boxReturn($context, $result);
    }
}
