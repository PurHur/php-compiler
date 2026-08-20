<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionEncodeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** PHP lowering for session_decode() — {@see __phpc_session_decode_apply} (#6086, #8252). */
final class JitSessionDecode
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('session_decode() expects exactly 1 argument in this compiler build');
        }

        // STANDALONE Type::register skips SessionEncodeRuntime::ensureLinked (#32994).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        SessionEncodeRuntime::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $dataStr = JitStringBuiltinArg::lower($context, $args[0], 'session_decode', 0, 'data');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_decode_apply'),
            $ptr,
            $dataStr
        );

        return $ptr;
    }
}
