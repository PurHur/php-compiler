<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GetBrowserRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_browser() — false when browscap ini unset (#11172). */
final class JitGetBrowser
{
    public static function invoke(Context $context): Value
    {
        GetBrowserRuntime::ensureLinked($context);

        $configured = $context->builder->call(
            $context->lookupFunction('__compiler_get_browser_browscap_configured')
        );
        $i32 = $context->getTypeFromString('int32');
        $ok = $context->builder->icmp(
            Builder::INT_NE,
            $configured,
            $i32->constInt(0, false)
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'get_browser_fail');
        $doneBlock = BasicBlockHelper::append($context, 'get_browser_done');
        $context->builder->branchIf($ok, $doneBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        // Browscap DB reader deferred — configured path still returns false for now.
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return $ptr;
    }
}
