<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\CallArgv;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for func_get_arg() / func_get_args() / func_num_args() (issues #197, #11614, #21984). */
final class JitFuncArgs
{
    private static function isUserFunctionContext(Context $context): bool
    {
        $block = $context->jitEnclosingBlock;

        return $block instanceof Block
            && null !== $block->func
            && !$block->isMainScript();
    }

    private static function emitGlobalScopeError(Context $context, string $message): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

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

    /**
     * Fixed (non-variadic) parameter JIT locals already materialized in scope (#21984).
     *
     * @return array<int, JITVariable>
     */
    private static function liveFixedParamVariables(Context $context, Block $block): array
    {
        $variadicIdx = $block->variadicParamIndex;
        $live = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $paramIdx = (int) $op->arg2;
            if (null !== $variadicIdx && $paramIdx >= $variadicIdx) {
                continue;
            }
            $slot = (int) $op->arg1;
            $operand = $block->getOperand($slot);
            if (null === $operand) {
                continue;
            }
            $var = self::findScopeVariable($context, $operand);
            if (null === $var) {
                continue;
            }
            $live[$paramIdx] = $var;
        }
        ksort($live);

        return $live;
    }

    private static function findScopeVariable(Context $context, object $operand): ?JITVariable
    {
        if (isset($context->scope->variables[$operand])) {
            return $context->scope->variables[$operand];
        }
        foreach ($context->scopeStack as $scope) {
            if (isset($scope->variables[$operand])) {
                return $scope->variables[$operand];
            }
        }

        return null;
    }

    /**
     * Overwrite CallArgv fixed-param slots with live locals; keep call-time count / extras (#21984).
     */
    private static function liveArgvHashtable(Context $context): JITVariable
    {
        $block = self::requireEnclosing($context);
        $src = self::callArgvHashtable($context);
        $live = self::liveFixedParamVariables($context, $block);
        if ([] === $live) {
            return $src;
        }

        $srcHt = $context->helper->loadValue($src);
        $i64 = $context->getTypeFromString('int64');
        $countRaw = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $srcHt
        );
        $countTy = $context->getStringFromType($countRaw->typeOf());
        $countI64 = ('int64' === $countTy || 'i64' === $countTy)
            ? $countRaw
            : $context->builder->zExt($countRaw, $i64);

        foreach ($live as $paramIdx => $var) {
            $idxConst = $i64->constInt((int) $paramIdx, false);
            $setBlock = BasicBlockHelper::append($context, 'func_args_live_set_'.$paramIdx);
            $contBlock = BasicBlockHelper::append($context, 'func_args_live_cont_'.$paramIdx);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_SLT, $idxConst, $countI64),
                $setBlock,
                $contBlock
            );

            $context->builder->positionAtEnd($setBlock);
            HashTableHelper::setAtIndex($context, $srcHt, $idxConst, $var);
            $context->builder->branch($contBlock);

            $context->builder->positionAtEnd($contBlock);
        }

        return $src;
    }

    public static function getArgs(Context $context): JITVariable
    {
        if (!self::isUserFunctionContext($context)) {
            self::emitGlobalScopeError($context, 'func_get_args() cannot be called from the global scope');

            return self::callArgvHashtable($context);
        }

        return self::liveArgvHashtable($context);
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
        $countTy = $context->getStringFromType($count->typeOf());
        $countI64 = ('int64' === $countTy || 'i64' === $countTy)
            ? $count
            : $context->builder->zExt($count, $context->getTypeFromString('int64'));
        JitValueBox::writeLong($context, $slot, $countI64);

        return $ptr;
    }

    /** @return Value */
    public static function getArg(Context $context, JITVariable $positionArg): Value
    {
        self::requireEnclosing($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        JitInternalStrictArg::requireInt($context, $positionArg, 'func_get_arg', 'position', 1);

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
        $countHt = self::callArgvHashtable($context);
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $context->helper->loadValue($countHt)
        );
        $countTy = $context->getStringFromType($count->typeOf());
        $countI64 = ('int64' === $countTy || 'i64' === $countTy)
            ? $count
            : $context->builder->zExt($count, $i64);
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
        $argvHt = self::liveArgvHashtable($context);
        $elem = HashTableHelper::readIndexedToValueBox($context, $context->helper->loadValue($argvHt), $position);

        return JitValueBox::pointer($context, $elem->value);
    }
}
