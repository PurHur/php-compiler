<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

/**
 * Placeholder kept on the self-host spine (#28432).
 *
 * Thin-AOT msg_* live in {@see \PHPCompiler\JIT\Builtin\MsgRuntime} as pure LLVM
 * (NestedJIT FFI unreliable under thin AOT — peer #28431 / #28433).
 */
final class MsgJitHelper
{
}
