<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmIniIntrospection;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionExtension::getINIEntries() (#34165).
 *
 * Extension name → assoc ini directive map (string|null local values),
 * or empty array when unknown. Bakes host Zend ini_get_all() tables via
 * {@see VmIniIntrospection} (same shape {@see \PHPCompiler\ext\standard\VmIni::reflectionIniEntries}).
 *
 * Peer memcmp tables: {@see ReflectionExtensionGetConstantsRuntime} (#34162).
 *
 * php-src: zim_ReflectionExtension_getINIEntries
 */
final class ReflectionExtensionGetINIEntriesRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* result slot (array)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_ext_gini';
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock($tag.'_merge');
        $miss = $fn->appendBasicBlock($tag.'_miss');
        $fold = $fn->appendBasicBlock($tag.'_fold');

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        $context->builder->positionAtEnd($entry);
        $buf = $context->builder->alloca($i8->arrayType(self::MAX_NAME_LEN));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt(self::MAX_NAME_LEN, false)
        );
        $context->builder->branchIf($tooLong, $miss, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($tag.'_fold_loop');
        $afterFold = $fn->appendBasicBlock($tag.'_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $foldDone = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock($tag.'_fold_body');
        $context->builder->branchIf($foldDone, $afterFold, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($nameCstr, $idx);
        $ch = $context->builder->load($srcPtr);
        $geA = $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('A'), true));
        $leZ = $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('Z'), true));
        $isUpper = $context->builder->and($geA, $leZ);
        $lowered = $context->builder->add($ch, $i8->constInt(32, true));
        $folded = $context->builder->select($isUpper, $lowered, $ch);
        $dstPtr = $context->builder->gep($bufPtr, $idx);
        $context->builder->store($folded, $dstPtr);
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxAlloca
        );
        $context->builder->branch($loop);

        $checkBlock = $afterFold;
        $hitIdx = 0;
        foreach (self::extensionLcToIniEntries() as $lcExt => $entries) {
            $wantLenInt = \strlen($lcExt);
            if (0 === $wantLenInt || [] === $entries) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock($tag.'_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock($tag.'_try_'.$hitIdx);
            $context->builder->positionAtEnd($checkBlock);

            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($lcExt);
            $wantStr = $context->builder->load($wantGlobal);
            $strMap = $context->structFieldMap['__string__'];
            $wantCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantStr, $strMap['value']),
                $i8p
            );
            $lenEq = $context->builder->icmp(Builder::INT_EQ, $nameLen, $wantLen);
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $bufPtr,
                $wantCstr,
                $context->builder->zExt($wantLen, $i64)
            );
            $nameEq = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $i32->constInt(0, false)
            );
            $match = $context->builder->and($lenEq, $nameEq);
            $context->builder->branchIf($match, $matchBlock, $nextCheck);

            $context->builder->positionAtEnd($matchBlock);
            self::storeIniEntriesMap($context, $resultSlot, $lcExt, $entries);
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        self::storeEmptyArray($context, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * @return array<string, array<string, ?string>> lowercase extension → directive → local value
     */
    private static function extensionLcToIniEntries(): array
    {
        VmIniIntrospection::seedHostIniRegistryFromZend();

        /** @var array<string, array<string, ?string>> $out */
        $out = [];
        // Bounded module list — skip core (~93 keys) which seals __init__ when baked
        // wholesale (#34165). `standard` is only ~14 directives and must be included
        // (#34188 leftover); Zend lists null locals as NULL, not omitted.
        foreach (['date', 'pcre', 'json', 'reflection', 'spl', 'tokenizer', 'standard'] as $lc) {
            $keys = VmIniIntrospection::registryKeysForExtension($lc);
            if (null === $keys || [] === $keys || \count($keys) > 32) {
                continue;
            }
            /** @var array<string, ?string> $map */
            $map = [];
            foreach ($keys as $key) {
                if (!\is_string($key) || '' === $key) {
                    continue;
                }
                $entry = VmIniIntrospection::registryEntry($key);
                // Keep null locals — php-src walks EG(ini_directives) and returns NULL
                // for unset values (ext/reflection/php_reflection.c).
                $local = null !== $entry ? ($entry['local_value'] ?? null) : null;
                $map[$key] = $local;
            }
            if ([] !== $map) {
                $out[$lc] = $map;
            }
        }
        ksort($out);

        return $out;
    }

    /** @param array<string, ?string> $entries */
    private static function storeIniEntriesMap(
        Context $context,
        Value $resultSlot,
        string $lcExt,
        array $entries
    ): void {
        if ([] === $entries) {
            self::storeEmptyArray($context, $resultSlot);

            return;
        }
        foreach ($entries as $local) {
            if (null === $local) {
                self::storeIniEntriesMapRuntime($context, $resultSlot, $entries);

                return;
            }
        }
        $ht = new HashTable();
        $parts = [];
        foreach ($entries as $name => $local) {
            $slot = new VMVariable();
            $slot->string((string) $local);
            $parts[] = $name.'=s:'.$local;
            $ht->add((string) $name, $slot);
        }
        sort($parts, \SORT_STRING);
        $cacheKey = 'refl_ext_gini_'.md5($lcExt.'|'.implode("\0", $parts));
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        JitValueBox::copyFromPointer($context, $resultSlot, $context->builder->load($global));
    }

    /** @param array<string, ?string> $entries */
    private static function storeIniEntriesMapRuntime(
        Context $context,
        Value $resultSlot,
        array $entries
    ): void {
        $ht = HashTableHelper::alloc($context);
        $n = \count($entries);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $ht,
            $context->constantFromInteger($n, 'size_t')
        );
        foreach ($entries as $name => $local) {
            $name = (string) $name;
            if ('' === $name) {
                continue;
            }
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            if (null === $local) {
                $nullVar = new Variable(
                    $context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $context->getTypeFromString('__value__*')->constNull()
                );
                $nullVar->isNullConstant = true;
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $nullVar);

                continue;
            }
            $valVar = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($local))
            );
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $valVar);
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $resultSlot),
            $ht
        );
    }

    private static function storeEmptyArray(Context $context, Value $resultSlot): void
    {
        $empty = ArrayBuiltinHelper::emptyArray($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $resultSlot),
            $empty
        );
    }
}
