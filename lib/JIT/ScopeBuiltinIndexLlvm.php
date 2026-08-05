<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ScopeBuiltinRuntime;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Named-local index dispatch for extract() / compact() JIT (#19035, php-in-PHP). */
final class ScopeBuiltinIndexLlvm
{
    private static int $blockSeq = 0;

    /**
     * @param array<string, Variable> $named
     */
    public static function compileKeyVarExists(
        Context $context,
        Value $keyStr,
        array $named
    ): Value {
        $names = array_keys($named);
        $n = \count($names);
        if (0 === $n) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        $index = ScopeBuiltinRuntime::matchNamedVariableIndex(
            $context,
            $keyStr,
            self::namedVariablesTable($named)
        );
        $i1 = $context->getTypeFromString('int1');
        $tag = 've'.(string) ++self::$blockSeq;
        $falseDone = BasicBlockHelper::append($context, 'extract_key_exists_false_'.$tag);
        $trueBlock = BasicBlockHelper::append($context, 'extract_key_exists_true_'.$tag);
        $phiBlock = BasicBlockHelper::append($context, 'extract_key_exists_phi_'.$tag);
        $entry = $context->builder->getInsertBlock();
        self::branchOnNamedVariableIndex(
            $context,
            $index,
            $named,
            'extract_key_exists_'.$tag,
            $falseDone,
            static function (Context $context, Variable $dest, string $name) use ($trueBlock, $falseDone): void {
                $isSet = IssetHelper::compile($context, $dest, null);
                $context->builder->branchIf($isSet, $trueBlock, $falseDone);
            },
            $entry
        );

        $context->builder->positionAtEnd($falseDone);
        $context->builder->branch($phiBlock);

        $context->builder->positionAtEnd($trueBlock);
        $context->builder->branch($phiBlock);

        $context->builder->positionAtEnd($phiBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $falseDone);
        $phi->addIncoming($i1->constInt(1, false), $trueBlock);

        return $phi;
    }

    public static function assignFromValueEntry(Context $context, Variable $dest, Value $entryPtr): void
    {
        if (Variable::TYPE_VALUE === $dest->type) {
            // Script globals store `__value__*` in a global slot — load before copy (#27520).
            $destPtr = $dest->functionStaticGlobal
                ? $context->builder->load($dest->value)
                : JitValueBox::pointer($context, $dest->value);
            JitValueBox::copyIntoPointer($context, $destPtr, $entryPtr);

            return;
        }
        if (Variable::TYPE_STRING === $dest->type) {
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $entryPtr
            );
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $dest->free();
            $context->builder->store($owned, $dest->value);
            $dest->addref();

            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $dest->type) {
            $longVal = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $entryPtr
            );
            $dest->free();
            $context->builder->store($longVal, $dest->value);
            $dest->addref();

            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            JitValueBox::writeBool(
                $context,
                $dest->value,
                $context->builder->truncOrBitCast(
                    $context->builder->call($context->lookupFunction('__value__readLong'), $entryPtr),
                    $context->getTypeFromString('int1')
                )
            );

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $dest->type) {
            $doubleVal = $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                $entryPtr
            );
            $dest->free();
            $context->builder->store($doubleVal, $dest->value);
            $dest->addref();

            return;
        }

        throw new \LogicException(
            'extract() target variable type not supported for JIT: '
            .Variable::getStringType($dest->type)
        );
    }

    /**
     * @param array<string, Variable> $named
     * @param callable(Context, Variable, string): void $onMatch  third arg is the variable name
     */
    public static function branchOnNamedVariableIndex(
        Context $context,
        Value $index,
        array $named,
        string $tag,
        BasicBlock $missBlock,
        callable $onMatch,
        ?BasicBlock $entryBlock = null
    ): void {
        $names = \array_keys($named);
        $n = \count($names);
        if (0 === $n) {
            if (null !== $entryBlock) {
                $context->builder->positionAtEnd($entryBlock);
            }
            $context->builder->branch($missBlock);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $minusOne = $i32->constInt(-1, true);
        $isMiss = $context->builder->icmp(Builder::INT_EQ, $index, $minusOne);
        $dispatchEntry = BasicBlockHelper::append($context, $tag.'_dispatch');
        if (null !== $entryBlock) {
            $context->builder->positionAtEnd($entryBlock);
        }
        $context->builder->branchIf($isMiss, $missBlock, $dispatchEntry);

        $checkBlocks = [$dispatchEntry];
        for ($i = 1; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, $tag.'_idx_'.$i);
        }

        foreach ($names as $i => $name) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $isCase = $context->builder->icmp(
                Builder::INT_EQ,
                $index,
                $i32->constInt($i, false)
            );
            $caseMatch = BasicBlockHelper::append($context, $tag.'_match_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $missBlock;
            $context->builder->branchIf($isCase, $caseMatch, $onMiss);

            $context->builder->positionAtEnd($caseMatch);
            $onMatch($context, $named[$name], $name);
        }
    }

    /**
     * @param array<string, Variable> $named
     */
    public static function namedVariablesTable(array $named): string
    {
        return \implode("\0", \array_keys($named));
    }
}
