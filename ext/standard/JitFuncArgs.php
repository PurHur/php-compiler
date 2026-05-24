<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\CallArgv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for func_get_args() / func_num_args() (issue #197). */
final class JitFuncArgs
{
    private static function requireEnclosing(Context $context): Block
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block || null === $block->func) {
            throw new \LogicException('Must be called from a function context');
        }

        return $block;
    }

    private static function callArgvHashtable(Context $context): JITVariable
    {
        return new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            CallArgv::load($context)
        );
    }

    public static function getArgs(Context $context): JITVariable
    {
        self::requireEnclosing($context);

        return self::callArgvHashtable($context);
    }

    /** @return Value */
    public static function numArgs(Context $context): Value
    {
        self::requireEnclosing($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $context->helper->loadValue(self::callArgvHashtable($context))
        );
        $countI64 = $context->builder->zExt(
            $count,
            $context->getTypeFromString('int64')
        );
        JitValueBox::writeLong($context, $slot, $countI64);

        return $ptr;
    }
}
