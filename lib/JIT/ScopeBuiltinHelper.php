<?php

declare(strict_types=1);

/**
 * LLVM helpers for extract() / compact() (caller scope import/export).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\VmScope;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ScopeBuiltinHelper
{
    private static int $blockSeq = 0;

    /**
     * @return array<string, Variable>
     */
    public static function namedVariablesInScope(Context $context): array
    {
        $map = [];
        foreach ($context->scope->variables as $op) {
            $name = OperandName::resolve($op);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            $var = $context->scope->variables[$op];
            if (Variable::TYPE_HASHTABLE === $var->type || 0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
                continue;
            }
            $map[$name] = $var;
        }

        return $map;
    }

    public static function findVariableByName(Context $context, string $name): ?Variable
    {
        return self::namedVariablesInScope($context)[$name] ?? null;
    }

    /**
     * @return Value
     * int64 import count
     */
    public static function extract(Context $context, Variable $array, ?Variable $flagsArg = null): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_HASHTABLE !== $array->type) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }

        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $flags = self::resolveFlags($context, $flagsArg);
        $named = self::namedVariablesInScope($context);
        if ([] === $named) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $i64 = $context->getTypeFromString('int64');
        $countSlot = $context->builder->alloca($i64, 1, 'extract_count');
        $context->builder->store($i64->constInt(0, false), $countSlot);

        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'extract_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $strHead = BasicBlockHelper::append($context, 'extract_str_head');
        $strBody = BasicBlockHelper::append($context, 'extract_str_body');
        $strNext = BasicBlockHelper::append($context, 'extract_str_next');
        $strDone = BasicBlockHelper::append($context, 'extract_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        self::importKeyIntoScope($context, $keyStr, $valEntry, $named, $flags, $countSlot);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $context->builder->load($countSlot);
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function importKeyIntoScope(
        Context $context,
        Value $keyStr,
        Value $valEntry,
        array $named,
        Value $flags,
        Value $countSlot
    ): void {
        if ([] === $named) {
            return;
        }

        $names = array_keys($named);
        $n = \count($names);
        $tag = 'e'.(string) ++self::$blockSeq;
        $done = BasicBlockHelper::append($context, 'extract_import_done_'.$tag);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'extract_import_check_'.$tag.'_'.$i);
        }

        foreach ($names as $i => $name) {
            $dest = $named[$name];
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $nameGlobal = $context->builder->load($context->constantStringFromString($name));
            $cmp = $context->builder->call(
                $context->lookupFunction('strcmp'),
                self::stringDataPtr($context, $keyStr),
                self::stringDataPtr($context, $nameGlobal)
            );
            $i32 = $context->getTypeFromString('int32');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $onMatch = BasicBlockHelper::append($context, 'extract_on_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $done;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            self::maybeAssignExtract($context, $dest, $valEntry, $flags, $countSlot, $done);
        }

        $context->builder->positionAtEnd($done);
    }

    private static function maybeAssignExtract(
        Context $context,
        Variable $dest,
        Value $valEntry,
        Value $flags,
        Value $countSlot,
        \PHPLLVM\BasicBlock $merge
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $skipMask = $i64->constInt(VmScope::EXTR_SKIP, false);
        $tag = 'a'.(string) ++self::$blockSeq;
        $assignBlock = BasicBlockHelper::append($context, 'extract_assign_'.$tag);
        $skipDone = BasicBlockHelper::append($context, 'extract_skip_done_'.$tag);

        $flagsAndSkip = $context->builder->and($flags, $skipMask);
        $skipEnabled = $context->builder->icmp(
            Builder::INT_EQ,
            $flagsAndSkip,
            $skipMask
        );
        $skipBlock = BasicBlockHelper::append($context, 'extract_skip_'.$tag);
        $context->builder->branchIf($skipEnabled, $skipBlock, $assignBlock);

        $context->builder->positionAtEnd($skipBlock);
        $isSet = IssetHelper::compile($context, $dest, null);
        $context->builder->branchIf($isSet, $skipDone, $assignBlock);

        $context->builder->positionAtEnd($skipDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($assignBlock);
        self::assignFromValueEntry($context, $dest, $valEntry);
        $prev = $context->builder->load($countSlot);
        $context->builder->store($context->builder->addNoSignedWrap($prev, $one), $countSlot);
        $context->builder->branch($merge);
    }

    public static function compact(Context $context, Variable ...$nameArgs): Value
    {
        if ([] === $nameArgs) {
            throw new \LogicException('compact() requires at least one argument in this compiler build');
        }

        $result = HashTableHelper::alloc($context);
        foreach ($nameArgs as $arg) {
            $name = self::resolveCompactName($context, $arg);
            $source = self::findVariableByName($context, $name);
            if (null === $source) {
                continue;
            }
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            self::storeVariableAtStringKey($context, $result, $keyStr, $source);
        }

        return $result;
    }

    private static function resolveCompactName(Context $context, Variable $arg): string
    {
        if (null !== $arg->compileTimeString) {
            return $arg->compileTimeString;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            throw new \LogicException(
                'compact() arguments must be string variable names in this compiler build'
            );
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            throw new \LogicException(
                'compact() arguments must be string variable names in this compiler build'
            );
        }

        throw new \LogicException(
            'compact() arguments must be string variable names in this compiler build'
        );
    }

    private static function resolveFlags(Context $context, ?Variable $flagsArg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $flagsArg) {
            return $i64->constInt(VmScope::EXTR_SKIP, false);
        }
        if (Variable::TYPE_NATIVE_LONG !== $flagsArg->type) {
            throw new \LogicException('extract() flags must be an integer in this compiler build');
        }

        return $context->helper->loadValue($flagsArg);
    }

    private static function storeVariableAtStringKey(
        Context $context,
        Value $ht,
        Value $keyStr,
        Variable $element
    ): void {
        switch ($element->type) {
            case Variable::TYPE_STRING:
                HashTableHelper::setAtStringKey(
                    $context,
                    $ht,
                    $keyStr,
                    $element
                );

                return;
            case Variable::TYPE_NATIVE_LONG:
            case Variable::TYPE_NATIVE_BOOL:
                HashTableHelper::setAtStringKey(
                    $context,
                    $ht,
                    $keyStr,
                    $element
                );

                return;
            case Variable::TYPE_VALUE:
                $valuePtr = $context->helper->loadValue($element);
                $valueMap = $context->structFieldMap['__value__'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($valuePtr, $valueMap['type'])
                );
                $i8 = $context->getTypeFromString('int8');
                $tag = 'c'.(string) ++self::$blockSeq;
                $isString = $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_STRING, false)
                );
                $stringBlock = BasicBlockHelper::append($context, 'compact_val_string_'.$tag);
                $longBlock = BasicBlockHelper::append($context, 'compact_val_long_'.$tag);
                $done = BasicBlockHelper::append($context, 'compact_val_done_'.$tag);
                $afterString = BasicBlockHelper::append($context, 'compact_val_after_string_'.$tag);
                $context->builder->branchIf($isString, $stringBlock, $afterString);

                $context->builder->positionAtEnd($stringBlock);
                $str = $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    $valuePtr
                );
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $str
                );
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyString'),
                    $ht,
                    $keyStr,
                    $owned
                );
                $context->builder->branch($done);

                $context->builder->positionAtEnd($afterString);
                $isLong = $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
                );
                $context->builder->branchIf($isLong, $longBlock, $done);

                $context->builder->positionAtEnd($longBlock);
                $longVal = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $keyStr,
                    $longVal
                );
                $context->builder->branch($done);

                $context->builder->positionAtEnd($done);

                return;
            default:
                throw new \LogicException(
                    'compact() variable type not supported for JIT: '
                    .Variable::getStringType($element->type)
                );
        }
    }

    private static function assignFromValueEntry(Context $context, Variable $dest, Value $entryPtr): void
    {
        if (Variable::TYPE_VALUE === $dest->type) {
            JitValueBox::copyFromPointer($context, $dest->value, $entryPtr);

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

    private static function stringDataPtr(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($str, $map['value']);
    }
}
