<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionEncodeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** PHP lowering for session_encode() — {@see __phpc_session_encode_apply} (#6086, #8252). */
final class JitSessionEncode
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_encode() expects exactly 0 arguments in this compiler build');
        }

        // STANDALONE Type::register skips SessionEncodeRuntime::ensureLinked (#32994).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        SessionEncodeRuntime::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_encode_apply'),
            $ptr
        );

        return $ptr;
    }
}
