<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\OutputRewriteVarsStorage;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal Store url_rewriter.tags into LLVM BSS (#27566).
 */
final class phpc_rewrite_vars_set_tags_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_rewrite_vars_set_tags_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_rewrite_vars_set_tags_kernel() expects exactly 1 argument');
        }
        $tags = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'phpc_rewrite_vars_set_tags_kernel',
            0,
            'tags'
        );
        OutputRewriteVarsJitHelper::setTags($tags);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_rewrite_vars_set_tags_kernel() expects exactly 1 argument');
        }
        $tags = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'phpc_rewrite_vars_set_tags_kernel',
            0,
            'tags'
        );
        OutputRewriteVarsStorage::emitSetTags($context, $tags);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }
}
