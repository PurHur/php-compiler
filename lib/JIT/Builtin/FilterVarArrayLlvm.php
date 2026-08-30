<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableWriteLlvm;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\filter\JitFilter;
use PHPCompiler\ext\filter\VmFilter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for filter_var_array() under thin AOT (#34574).
 *
 * NestedJIT of FilterBatchJitHelper SIGSEGVs (`new HashTable()` / iterateKeyed).
 * Const folds live in {@see FilterVarArrayRuntime}; this class covers runtime args.
 *
 * php-src: ext/filter/filter.c — php_filter_var_array
 */
final class FilterVarArrayLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function filter(
        Context $context,
        JITVariable $data,
        JITVariable $definition,
        int $addEmpty
    ): Value {
        $dataHt = ArrayBuiltinHelper::loadHashTable($context, $data);
        if (self::isArrayDefinition($definition)) {
            $defHt = ArrayBuiltinHelper::loadHashTable($context, $definition);

            return self::filterByDefinition($context, $dataHt, $defHt, $addEmpty);
        }
        $filterId = JitLongArg::lower($context, $definition, 'filter_var_array() definition');

        return self::filterById($context, $dataHt, $filterId);
    }

    /** Used by {@see FilterVarArrayRuntime} const-fold dispatch. */
    public static function isArrayDefinitionPublic(JITVariable $definition): bool
    {
        return self::isArrayDefinition($definition);
    }

    private static function isArrayDefinition(JITVariable $definition): bool
    {
        if (\is_array($definition->compileTimeAssoc)) {
            return true;
        }
        if ($definition->valueBoxHashtable) {
            return true;
        }
        if (JITVariable::TYPE_HASHTABLE === $definition->type
            || ArrayBuiltinHelper::isNativeArray($definition->type)) {
            return true;
        }
        // Boxed HT: no scalar fold hints (FILTER_* keeps compileTimeLong).
        if (JITVariable::TYPE_VALUE === $definition->type
            && null === $definition->compileTimeLong
            && null === $definition->compileTimeString
            && null === $definition->compileTimeFloat) {
            return true;
        }

        return false;
    }

    /** Map one filter id over every element — peer of filter_var(FILTER_REQUIRE_ARRAY) (#29047). */
    public static function mapByFilterId(Context $context, Value $srcHt, int $filterId): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return self::filterById($context, $srcHt, $i64->constInt($filterId, false));
    }

    private static function filterById(Context $context, Value $srcHt, Value $filterId): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::mapPacked($context, $srcHt, $dest, $filterId);
        self::mapStringKeys($context, $srcHt, $dest, $filterId);

        return $dest;
    }

    private static function filterByDefinition(
        Context $context,
        Value $dataHt,
        Value $defHt,
        int $addEmpty
    ): Value {
        $dest = HashTableHelper::alloc($context);
        self::mapDefinitionStringKeys($context, $dataHt, $defHt, $dest, $addEmpty);
        self::mapDefinitionPacked($context, $dataHt, $defHt, $dest, $addEmpty);

        return $dest;
    }

    private static function mapPacked(
        Context $context,
        Value $src,
        Value $dest,
        Value $filterId
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $htMap['nextFreeElement'])
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'fva_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'fva_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'fva_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'fva_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'fva_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $context->builder->branchIf(
            HashTableReadLlvm::packedIndexIsUndefined($context, $src, $idx),
            $next,
            $take
        );

        $context->builder->positionAtEnd($take);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        HashTableWriteLlvm::setAtIndex(
            $context,
            $dest,
            $idx,
            self::applyFilter($context, $valVar, $filterId)
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function mapStringKeys(
        Context $context,
        Value $src,
        Value $dest,
        Value $filterId
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($src, $htMap['strKeys'])),
            $nodeSlot
        );

        $head = BasicBlockHelper::append($context, 'fva_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'fva_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'fva_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'fva_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull()),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $valVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $valSlot);
        HashTableWriteLlvm::setAtStringKey(
            $context,
            $dest,
            $keyStr,
            self::applyFilter($context, $valVar, $filterId)
        );
        $context->builder->store(
            $context->builder->load($context->builder->structGep($node, $nodeMap['next'])),
            $nodeSlot
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function mapDefinitionStringKeys(
        Context $context,
        Value $dataHt,
        Value $defHt,
        Value $dest,
        int $addEmpty
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($defHt, $htMap['strKeys'])),
            $nodeSlot
        );

        $head = BasicBlockHelper::append($context, 'fva_def_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'fva_def_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'fva_def_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'fva_def_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull()),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $filterField = $context->builder->structGep($node, $nodeMap['value']);
        $filterSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $filterSlot, $filterField);
        $filterVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $filterSlot
        );
        $filterId = JitLongArg::lower($context, $filterVar, 'filter_var_array() options filter');

        $foundPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $dataHt,
            $keyStr
        );
        $missing = $context->builder->icmp(Builder::INT_EQ, $foundPtr, $valuePtr->constNull());
        $haveBb = BasicBlockHelper::append($context, 'fva_def_sk_have_'.$tag);
        $missBb = BasicBlockHelper::append($context, 'fva_def_sk_miss_'.$tag);
        $contBb = BasicBlockHelper::append($context, 'fva_def_sk_cont_'.$tag);
        $context->builder->branchIf($missing, $missBb, $haveBb);

        $context->builder->positionAtEnd($haveBb);
        $dataSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $dataSlot, $foundPtr);
        $dataVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $dataSlot
        );
        HashTableWriteLlvm::setAtStringKey(
            $context,
            $dest,
            $keyStr,
            self::applyFilter($context, $dataVar, $filterId)
        );
        $context->builder->branch($contBb);

        $context->builder->positionAtEnd($missBb);
        if (0 !== $addEmpty) {
            $falseSlot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $falseSlot, $context->constantFromBool(false));
            HashTableWriteLlvm::setAtStringKey(
                $context,
                $dest,
                $keyStr,
                new JITVariable(
                    $context,
                    JITVariable::TYPE_VALUE,
                    JITVariable::KIND_VARIABLE,
                    $falseSlot
                )
            );
        }
        $context->builder->branch($contBb);

        $context->builder->positionAtEnd($contBb);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($node, $nodeMap['next'])),
            $nodeSlot
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function mapDefinitionPacked(
        Context $context,
        Value $dataHt,
        Value $defHt,
        Value $dest,
        int $addEmpty
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($defHt, $htMap['nextFreeElement'])
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'fva_def_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'fva_def_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'fva_def_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'fva_def_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'fva_def_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $context->builder->branchIf(
            HashTableReadLlvm::packedIndexIsUndefined($context, $defHt, $idx),
            $next,
            $take
        );

        $context->builder->positionAtEnd($take);
        $filterVar = HashTableReadLlvm::readIndexedToValueBox($context, $defHt, $idx);
        $filterId = JitLongArg::lower($context, $filterVar, 'filter_var_array() options filter');
        $dataUndef = HashTableReadLlvm::packedIndexIsUndefined($context, $dataHt, $idx);
        $haveBb = BasicBlockHelper::append($context, 'fva_def_pk_have_'.$tag);
        $missBb = BasicBlockHelper::append($context, 'fva_def_pk_miss_'.$tag);
        $contBb = BasicBlockHelper::append($context, 'fva_def_pk_cont_'.$tag);
        $context->builder->branchIf($dataUndef, $missBb, $haveBb);

        $context->builder->positionAtEnd($haveBb);
        $dataVal = HashTableReadLlvm::readIndexedToValueBox($context, $dataHt, $idx);
        HashTableWriteLlvm::setAtIndex(
            $context,
            $dest,
            $idx,
            self::applyFilter($context, $dataVal, $filterId)
        );
        $context->builder->branch($contBb);

        $context->builder->positionAtEnd($missBb);
        if (0 !== $addEmpty) {
            $falseSlot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $falseSlot, $context->constantFromBool(false));
            HashTableWriteLlvm::setAtIndex(
                $context,
                $dest,
                $idx,
                new JITVariable(
                    $context,
                    JITVariable::TYPE_VALUE,
                    JITVariable::KIND_VARIABLE,
                    $falseSlot
                )
            );
        }
        $context->builder->branch($contBb);

        $context->builder->positionAtEnd($contBb);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function applyFilter(
        Context $context,
        JITVariable $value,
        Value $filterId
    ): JITVariable {
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) self::nextSeq();
        $resultSlot = JitValueBox::alloc($context);

        $intBb = BasicBlockHelper::append($context, 'fva_filt_int_'.$tag);
        $boolBb = BasicBlockHelper::append($context, 'fva_filt_bool_'.$tag);
        $floatBb = BasicBlockHelper::append($context, 'fva_filt_float_'.$tag);
        $emailBb = BasicBlockHelper::append($context, 'fva_filt_email_'.$tag);
        $urlBb = BasicBlockHelper::append($context, 'fva_filt_url_'.$tag);
        $ipBb = BasicBlockHelper::append($context, 'fva_filt_ip_'.$tag);
        $macBb = BasicBlockHelper::append($context, 'fva_filt_mac_'.$tag);
        $domainBb = BasicBlockHelper::append($context, 'fva_filt_domain_'.$tag);
        $defaultBb = BasicBlockHelper::append($context, 'fva_filt_default_'.$tag);
        $failBb = BasicBlockHelper::append($context, 'fva_filt_fail_'.$tag);
        $mergeBb = BasicBlockHelper::append($context, 'fva_filt_merge_'.$tag);

        // INT/BOOL/FLOAT/DEFAULT + EMAIL/URL/IP/MAC/DOMAIN (#35016 leftover of #34574).
        $switch = $context->builder->branchSwitch($filterId, $failBb, 9);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_INT, false), $intBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_BOOLEAN, false), $boolBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_FLOAT, false), $floatBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_EMAIL, false), $emailBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_URL, false), $urlBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_IP, false), $ipBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_MAC, false), $macBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_VALIDATE_DOMAIN, false), $domainBb);
        $switch->addCase($i64->constInt(VmFilter::FILTER_DEFAULT, false), $defaultBb);

        $context->builder->positionAtEnd($intBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateInt($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($boolBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateBoolean($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($floatBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateFloat($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($emailBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateEmail($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($urlBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateUrl($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($ipBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateIp($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($macBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateMac($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($domainBb);
        JitValueBox::copyFromPointer($context, $resultSlot, JitFilter::validateDomain($context, $value));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($defaultBb);
        $strPtr = (new \PHPCompiler\ext\standard\strval())->call($context, $value);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $strPtr
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $resultSlot, $context->constantFromBool(false));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $resultSlot
        );
    }
}
