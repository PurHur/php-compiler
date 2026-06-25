<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\CallArgv;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for func_get_arg() / func_get_args() / func_num_args() (issues #197, #11614). */
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

    /** @return Value */
    public static function getArg(Context $context, JITVariable $positionArg): Value
    {
        self::requireEnclosing($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $position = JitZendScalarCast::emitIntCast($context, $positionArg);
        $zero = $i64->constInt(0, false);
        $negBlock = BasicBlockHelper::append($context, 'func_get_arg_neg');
        $rangeOkBlock = BasicBlockHelper::append($context, 'func_get_arg_range_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $position, $zero),
            $negBlock,
            $rangeOkBlock
        );

        $context->builder->positionAtEnd($negBlock);
        TypeErrorRaise::emitValueError(
            $context,
            'func_get_arg(): Argument #1 ($position) must be greater than or equal to 0'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($rangeOkBlock);
        $argvHt = self::callArgvHashtable($context);
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $context->helper->loadValue($argvHt)
        );
        $countI64 = $context->builder->zExt($count, $i64);
        $oobBlock = BasicBlockHelper::append($context, 'func_get_arg_oob');
        $okBlock = BasicBlockHelper::append($context, 'func_get_arg_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $position, $countI64),
            $oobBlock,
            $okBlock
        );

        $context->builder->positionAtEnd($oobBlock);
        TypeErrorRaise::emitValueError(
            $context,
            'func_get_arg(): Argument #1 ($position) must be less than the number of the arguments passed to the currently executed function'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($okBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $context->helper->loadValue($argvHt), $position);

        return JitValueBox::pointer($context, $elem->value);
    }
}
