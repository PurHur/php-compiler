<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\ext\standard\VmScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering for extract() / compact() caller-scope import (issues #275, #476).
 */
final class ScopeBuiltinHelper
{
    /**
     * @return array<string, Variable>
     */
    private static function callerVariables(Context $context, Block $block): array
    {
        $func = BasicBlockHelper::parentFunction($context);
        $bb = $context->builder->getInsertBlock();
        $map = [];
        foreach ($block->namedOperands() as $name => $operand) {
            if (!$context->hasVariableOp($operand)) {
                $context->makeVariableFromOp($func, $bb, $block, $operand);
            }
            $map[$name] = $context->getVariableFromOp($operand);
        }

        return $map;
    }

    public static function compileExtract(Context $context, Variable $arrayArg, ?Variable $flagsArg = null): Value
    {
        $block = $context->compilingBlock;
        if (null === $block) {
            throw new \LogicException('extract() requires an active compiling block in JIT');
        }
        if (Variable::TYPE_HASHTABLE !== $arrayArg->type) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }

        $ht = $context->helper->loadValue($arrayArg);
        $flags = self::flagsValue($context, $flagsArg);
        $nameMap = self::callerVariables($context, $block);

        $i64 = $context->getTypeFromString('int64');
        $countSlot = $context->builder->alloca($i64, 1, 'extract_count');
        $context->builder->store($i64->constInt(0, false), $countSlot);

        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'extract_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $loopHead = BasicBlockHelper::append($context, 'extract_loop_head');
        $loopBody = BasicBlockHelper::append($context, 'extract_loop_body');
        $loopNext = BasicBlockHelper::append($context, 'extract_loop_next');
        $done = BasicBlockHelper::append($context, 'extract_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $node = $context->builder->load($walkSlot);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        self::importMatchingKey($context, $nameMap, $keyStr, $valEntry, $flags, $countSlot, $loopNext);

        $context->builder->positionAtEnd($loopNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    public static function compileCompact(Context $context, Variable ...$nameArgs): Value
    {
        $block = $context->compilingBlock;
        if (null === $block) {
            throw new \LogicException('compact() requires an active compiling block in JIT');
        }
        if ([] === $nameArgs) {
            throw new \LogicException('compact() requires at least one argument in this compiler build');
        }

        $result = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $context->builder->alloca($context->getTypeFromString('__hashtable__*'))
        );
        HashTableHelper::initArray($context, $result);
        $ht = $context->helper->loadValue($result);
        $nameMap = self::callerVariables($context, $block);

        foreach ($nameArgs as $arg) {
            $name = self::literalName($arg);
            if (!isset($nameMap[$name])) {
                continue;
            }
            $src = $nameMap[$name];
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            $valBox = self::readVariableAsValueBox($context, $src);
            ArrayBuiltinHelper::storeValueEntryAtStringKey(
                $context,
                $ht,
                $keyStr,
                JitValueBox::pointer($context, $valBox->value)
            );
        }

        return $context->helper->loadValue($result);
    }

    private static function flagsValue(Context $context, ?Variable $flagsArg): Value
    {
        if (null === $flagsArg) {
            return $context->constantFromInteger(VmScope::EXTR_SKIP, 'int64');
        }
        if (Variable::TYPE_NATIVE_LONG === $flagsArg->type) {
            return $context->helper->loadValue($flagsArg);
        }
        if (Variable::TYPE_VALUE === $flagsArg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $context->helper->loadValue($flagsArg)
            );
        }

        throw new \LogicException('extract() flags must be an integer in this compiler build');
    }

    private static function literalName(Variable $arg): string
    {
        if (null !== $arg->compileTimeString) {
            return $arg->compileTimeString;
        }
        if (Variable::TYPE_STRING === $arg->type && Variable::KIND_VALUE === $arg->kind) {
            throw new \LogicException('compact() arguments must be string variable names in this compiler build');
        }

        throw new \LogicException('compact() arguments must be string variable names in this compiler build');
    }

    /**
     * @param array<string, Variable> $nameMap
     */
    private static function importMatchingKey(
        Context $context,
        array $nameMap,
        Value $keyStr,
        Value $srcValuePtr,
        Value $flags,
        Value $countSlot,
        BasicBlock $loopNext
    ): void {
        $names = array_keys($nameMap);
        if ([] === $names) {
            $context->builder->branch($loopNext);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $extrSkip = $context->constantFromInteger(VmScope::EXTR_SKIP, 'int64');
        $skipMask = $context->builder->and($flags, $extrSkip);
        $useSkip = $context->builder->icmp(Builder::INT_NE, $skipMask, $i64->constInt(0, false));

        $tryBlocks = [];
        foreach ($names as $idx => $name) {
            $tryBlocks[$name] = BasicBlockHelper::append($context, 'extract_try_'.$name);
        }
        $noMatch = BasicBlockHelper::append($context, 'extract_nomatch');

        $context->builder->branch($tryBlocks[$names[0]]);

        foreach ($names as $idx => $name) {
            $dest = $nameMap[$name];
            $failTarget = isset($names[$idx + 1]) ? $tryBlocks[$names[$idx + 1]] : $noMatch;

            $context->builder->positionAtEnd($tryBlocks[$name]);
            $constKey = $context->builder->load($context->constantStringFromString($name));
            $matches = JitStringCompare::identical($context, $keyStr, $constKey);
            $importBlock = BasicBlockHelper::append($context, 'extract_import_'.$name);
            $context->builder->branchIf($matches, $importBlock, $failTarget);

            $context->builder->positionAtEnd($importBlock);
            $doImport = BasicBlockHelper::append($context, 'extract_do_'.$name);
            $skipImport = BasicBlockHelper::append($context, 'extract_skip_'.$name);
            $context->builder->branchIf($useSkip, $skipImport, $doImport);

            $context->builder->positionAtEnd($skipImport);
            $isSet = IssetHelper::compile($context, $dest, null, null, null);
            $context->builder->branchIf($isSet, $noMatch, $doImport);

            $context->builder->positionAtEnd($doImport);
            self::importValue($context, $dest, $srcValuePtr);
            $count = $context->builder->load($countSlot);
            $context->builder->store($context->builder->addNoSignedWrap($count, $one), $countSlot);
            $context->builder->branch($loopNext);
        }

        $context->builder->positionAtEnd($noMatch);
        $context->builder->branch($loopNext);
    }

    private static function importValue(Context $context, Variable $dest, Value $srcValuePtr): void
    {
        if (Variable::KIND_VARIABLE !== $dest->kind) {
            throw new \LogicException('extract() import target must be a variable slot');
        }
        switch ($dest->type) {
            case Variable::TYPE_VALUE:
                JitValueBox::copyFromPointer($context, $dest->value, $srcValuePtr);
                return;
            case Variable::TYPE_STRING:
                $dest->free();
                $str = $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    $srcValuePtr
                );
                $context->builder->store($str, $dest->value);
                $dest->addref();
                return;
            case Variable::TYPE_HASHTABLE:
                $dest->free();
                $ht = $context->builder->call(
                    $context->lookupFunction('__value__readHashtable'),
                    $srcValuePtr
                );
                $context->builder->store($ht, $dest->value);
                $dest->addref();
                return;
            case Variable::TYPE_NATIVE_LONG:
                $dest->free();
                $long = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $srcValuePtr
                );
                $context->builder->store($long, $dest->value);
                $dest->addref();
                return;
            case Variable::TYPE_NATIVE_DOUBLE:
                $dest->free();
                $dbl = $context->builder->call(
                    $context->lookupFunction('__value__readDouble'),
                    $srcValuePtr
                );
                $context->builder->store($dbl, $dest->value);
                $dest->addref();
                return;
            case Variable::TYPE_NATIVE_BOOL:
                $boolVal = $context->builder->truncOrBitCast(
                    $context->builder->call(
                        $context->lookupFunction('__value__readLong'),
                        $srcValuePtr
                    ),
                    $context->getTypeFromString('int1')
                );
                $context->builder->store($boolVal, $dest->value);
                return;
            default:
                throw new \LogicException(
                    'extract() import into '
                    .Variable::getStringType($dest->type)
                    .' is not implemented for JIT in this compiler build'
                );
        }
    }

    private static function readVariableAsValueBox(Context $context, Variable $src): Variable
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($src->type) {
            case Variable::TYPE_VALUE:
                if (Variable::KIND_VARIABLE === $src->kind) {
                    JitValueBox::copyFromPointer($context, $slot, $src->value);
                } else {
                    JitValueBox::copyFromPointer($context, $slot, $context->helper->loadValue($src));
                }
                break;
            case Variable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong(
                    $context,
                    $slot,
                    $context->helper->loadValue($src)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                JitValueBox::writeBool($context, $slot, $context->helper->loadValue($src));
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->helper->loadValue($src)
                );
                break;
            case Variable::TYPE_STRING:
                $str = $context->helper->loadValue($src);
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $str
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );
                break;
            case Variable::TYPE_HASHTABLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $ptr,
                    $context->helper->loadValue($src)
                );
                break;
            default:
                throw new \LogicException(
                    'compact() cannot read variable of type '
                    .Variable::getStringType($src->type)
                    .' for JIT in this compiler build'
                );
        }

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }
}
