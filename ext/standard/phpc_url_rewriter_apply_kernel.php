<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\UrlRewriterApplyRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal URL-Rewriter flush kernel for ObOutputJitHelper::applyHandler (#27566).
 *
 * Keeps UrlScannerEx / VmUrlRewriterFlush out of ObOutput NestedJIT (peer
 * {@see phpc_ob_write_stdout_kernel} / #21469) — NestedJIT of the scanner into
 * ObOutput miscompiled the OB stack under thin AOT.
 */
final class phpc_url_rewriter_apply_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_url_rewriter_apply_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_url_rewriter_apply_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $content = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'phpc_url_rewriter_apply_kernel',
            0,
            'content'
        );
        $out = VmUrlRewriterFlush::applyHandler($content);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_url_rewriter_apply_kernel() expects exactly 1 argument');
        }
        $content = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'phpc_url_rewriter_apply_kernel',
            0,
            'content'
        );

        // Return `__string__*` — declare-only during ObOutput NestedJIT (#27566).
        return UrlRewriterApplyRuntime::emitApplyCall($context, $content);
    }
}
