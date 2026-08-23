<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionClass::isIterable() / isIterateable() (#34062, #18297).
 *
 * Thin AOT previously called {@see invoke()} without {@see ensureLinked()}, so
 * lookup of `__phpc_refl_class_is_iterateable` failed. NestedJIT bool bridges
 * ({@see JitVmHelperLink}) also fail module verify under thin AOT — same class
 * as #34032 / #34027. Compile-unit lowercase {@see memcmp} name table matches Zend.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_isIterable
 * (interfaces → false; else ce implements Traversable).
 */
final class ReflectionClassIsIterateableRuntime
{
    private const ABI = '__phpc_refl_class_is_iterateable';

    public static function invoke(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $nameCstr,
            $nameLen
        );
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::ensureMemcmpDecl($context);

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($i1, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_class_is_iterateable_entry');
        $fold = $fn->appendBasicBlock('refl_class_is_iterateable_fold');
        $context->builder->positionAtEnd($entry);
        $nameCstr = $fn->getParam(0);
        $nameLen = $fn->getParam(1);

        $maxLen = 512;
        $buf = $context->builder->alloca($i8->arrayType($maxLen));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt($maxLen, false)
        );
        $trueBlock = $fn->appendBasicBlock('refl_class_iterateable_yes');
        $falseBlock = $fn->appendBasicBlock('refl_class_iterateable_no');
        // Oversize names cannot match → not iterable.
        $context->builder->branchIf($tooLong, $falseBlock, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock('refl_isit_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_isit_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_isit_fold_body');
        $context->builder->branchIf($done, $afterFold, $body);

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

        $context->builder->positionAtEnd($afterFold);
        $matchNames = self::iterableLowerNames($context);
        $checkBlock = $afterFold;
        $n = \count($matchNames);
        foreach ($matchNames as $idxName => $lcName) {
            $context->builder->positionAtEnd($checkBlock);
            $wantLenInt = \strlen($lcName);
            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($lcName);
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
            $nameEq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $match = $context->builder->and($lenEq, $nameEq);
            $next = ($idxName === $n - 1)
                ? $falseBlock
                : $fn->appendBasicBlock('refl_isit_try_'.($idxName + 1));
            $context->builder->branchIf($match, $trueBlock, $next);
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($falseBlock);
        }

        $context->builder->positionAtEnd($trueBlock);
        $context->builder->returnValue($i1->constInt(1, false));
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Lowercase class names that must report isIterable()===true.
     *
     * @return list<string>
     */
    private static function iterableLowerNames(Context $context): array
    {
        $seen = [];
        $add = static function (string $display) use (&$seen): void {
            $lc = strtolower(ltrim($display, '\\'));
            if ('' !== $lc) {
                $seen[$lc] = true;
            }
        };

        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $classId => $className) {
            $classId = (int) $classId;
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display) {
                continue;
            }
            $lc = strtolower(ltrim($display, '\\'));
            // php-src: interfaces report isIterable=false even when they extend Traversable.
            if ($object->isInterfaceClassLc($lc)) {
                continue;
            }
            $ifaces = $object->allInterfacesForClassLc($lc);
            if (\in_array('traversable', $ifaces, true)) {
                $add($display);
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry) {
                    continue;
                }
                if (ReflectionSupport::reflectionClassIsIterateable($entry, $vmCtx)) {
                    $add($entry->name);
                }
            }
        }

        foreach (self::knownBuiltinIterableClassNames() as $builtin) {
            $add($builtin);
        }

        $out = array_keys($seen);
        sort($out);

        return $out;
    }

    /**
     * Builtin Traversable implementors commonly reflected without a local class decl.
     *
     * @return list<string>
     */
    private static function knownBuiltinIterableClassNames(): array
    {
        return [
            'ArrayIterator',
            'ArrayObject',
            'CachingIterator',
            'CallbackFilterIterator',
            'DirectoryIterator',
            'EmptyIterator',
            'FilesystemIterator',
            'FilterIterator',
            'GlobIterator',
            'InfiniteIterator',
            'IteratorIterator',
            'LimitIterator',
            'MultipleIterator',
            'NoRewindIterator',
            'ParentIterator',
            'RecursiveArrayIterator',
            'RecursiveCachingIterator',
            'RecursiveCallbackFilterIterator',
            'RecursiveDirectoryIterator',
            'RecursiveFilterIterator',
            'RecursiveIteratorIterator',
            'RecursiveRegexIterator',
            'RecursiveTreeIterator',
            'RegexIterator',
            'SimpleXMLElement',
            'SimpleXMLIterator',
            'SplDoublyLinkedList',
            'SplFixedArray',
            'SplHeap',
            'SplMaxHeap',
            'SplMinHeap',
            'SplObjectStorage',
            'SplPriorityQueue',
            'SplQueue',
            'SplStack',
        ];
    }
}
