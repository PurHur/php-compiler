<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT (class, target) tables for ReflectionClass::implementsInterface / isSubclassOf (#34080).
 *
 * Unbound proxies previously returned NULL → false. Compile-unit lowercase class +
 * target memcmp tables match Zend (peer of {@see ReflectionClassHasMemberRuntime}).
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_implementsInterface
 * / zim_ReflectionClass_isSubclassOf
 */
final class ReflectionClassRelationQueryRuntime
{
    /** @var array<string, string> kind => ABI */
    private const ABI = [
        'implementsinterface' => '__phpc_refl_class_implements_interface',
        'issubclassof' => '__phpc_refl_class_is_subclass_of',
    ];

    public static function invoke(
        Context $context,
        string $kind,
        Value $classCstr,
        Value $classLen,
        Value $targetCstr,
        Value $targetLen,
    ): Value {
        $kindLc = strtolower($kind);
        if (!isset(self::ABI[$kindLc])) {
            throw new \InvalidArgumentException('Unknown ReflectionClass relation kind: '.$kind);
        }
        self::ensureLinked($context, $kindLc);

        return $context->builder->call(
            $context->lookupFunction(self::ABI[$kindLc]),
            $classCstr,
            $classLen,
            $targetCstr,
            $targetLen
        );
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        foreach (array_keys(self::ABI) as $kindLc) {
            self::ensureLinked($context, $kindLc);
        }
    }

    public static function ensureLinked(Context $context, string $kindLc): void
    {
        $kindLc = strtolower($kindLc);
        $abi = self::ABI[$kindLc] ?? null;
        if (null === $abi) {
            throw new \InvalidArgumentException('Unknown ReflectionClass relation kind: '.$kindLc);
        }

        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

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
        $ft = $context->context->functionType($i1, false, $i8p, $sizeT, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $tag = 'refl_'.$kindLc;
        $entry = $fn->appendBasicBlock($tag.'_entry');
        $foldClass = $fn->appendBasicBlock($tag.'_fold_class');
        $foldTarget = $fn->appendBasicBlock($tag.'_fold_target');
        $trueBlock = $fn->appendBasicBlock($tag.'_yes');
        $falseBlock = $fn->appendBasicBlock($tag.'_no');

        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $classLen = $fn->getParam(1);
        $targetCstr = $fn->getParam(2);
        $targetLen = $fn->getParam(3);

        $maxLen = 512;
        $classBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $classBufPtr = $context->builder->pointerCast($classBuf, $i8p);
        $targetBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $targetBufPtr = $context->builder->pointerCast($targetBuf, $i8p);

        $classTooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $classLen,
            $sizeT->constInt($maxLen, false)
        );
        $targetTooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $targetLen,
            $sizeT->constInt($maxLen, false)
        );
        $tooLong = $context->builder->or($classTooLong, $targetTooLong);
        $context->builder->branchIf($tooLong, $falseBlock, $foldClass);

        self::emitFoldAscii(
            $context,
            $fn,
            $tag.'_c',
            $foldClass,
            $foldTarget,
            $classCstr,
            $classLen,
            $classBufPtr,
            $sizeT,
            $i8,
            true
        );
        self::emitFoldAscii(
            $context,
            $fn,
            $tag.'_t',
            $foldTarget,
            null,
            $targetCstr,
            $targetLen,
            $targetBufPtr,
            $sizeT,
            $i8,
            true
        );
        $afterFold = $context->builder->getInsertBlock();

        $pairs = self::positivePairs($context, $kindLc);
        $checkBlock = $afterFold;
        $n = \count($pairs);
        foreach ($pairs as $idx => $pair) {
            [$classLc, $targetLc] = $pair;
            $context->builder->positionAtEnd($checkBlock);

            $wantClassLen = $sizeT->constInt(\strlen($classLc), false);
            $wantClassGlobal = $context->constantStringFromString($classLc);
            $wantClassStr = $context->builder->load($wantClassGlobal);
            $strMap = $context->structFieldMap['__string__'];
            $wantClassCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantClassStr, $strMap['value']),
                $i8p
            );
            $classLenEq = $context->builder->icmp(Builder::INT_EQ, $classLen, $wantClassLen);
            $classCmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $classBufPtr,
                $wantClassCstr,
                $context->builder->zExt($wantClassLen, $i64)
            );
            $classEq = $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false));

            $wantTargetLen = $sizeT->constInt(\strlen($targetLc), false);
            $wantTargetGlobal = $context->constantStringFromString($targetLc);
            $wantTargetStr = $context->builder->load($wantTargetGlobal);
            $wantTargetCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantTargetStr, $strMap['value']),
                $i8p
            );
            $targetLenEq = $context->builder->icmp(Builder::INT_EQ, $targetLen, $wantTargetLen);
            $targetCmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $targetBufPtr,
                $wantTargetCstr,
                $context->builder->zExt($wantTargetLen, $i64)
            );
            $targetEq = $context->builder->icmp(Builder::INT_EQ, $targetCmp, $i32->constInt(0, false));

            $match = $context->builder->and(
                $context->builder->and($classLenEq, $classEq),
                $context->builder->and($targetLenEq, $targetEq)
            );
            $next = ($idx === $n - 1)
                ? $falseBlock
                : $fn->appendBasicBlock($tag.'_try_'.($idx + 1));
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

        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Fold or copy ASCII into $bufPtr. When $afterBlock is null, leaves insert at after-fold block.
     *
     * @param mixed $fn
     * @param mixed $foldBlock
     * @param mixed $afterBlock
     * @param mixed $srcCstr
     * @param mixed $srcLen
     * @param mixed $bufPtr
     * @param mixed $sizeT
     * @param mixed $i8
     */
    private static function emitFoldAscii(
        Context $context,
        $fn,
        string $tag,
        $foldBlock,
        $afterBlock,
        $srcCstr,
        $srcLen,
        $bufPtr,
        $sizeT,
        $i8,
        bool $foldCase,
    ): void {
        $context->builder->positionAtEnd($foldBlock);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($tag.'_loop');
        $after = $afterBlock ?? $fn->appendBasicBlock($tag.'_after');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $srcLen);
        $body = $fn->appendBasicBlock($tag.'_body');
        $context->builder->branchIf($done, $after, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($srcCstr, $idx);
        $ch = $context->builder->load($srcPtr);
        if ($foldCase) {
            $geA = $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('A'), true));
            $leZ = $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('Z'), true));
            $isUpper = $context->builder->and($geA, $leZ);
            $lowered = $context->builder->add($ch, $i8->constInt(32, true));
            $folded = $context->builder->select($isUpper, $lowered, $ch);
        } else {
            $folded = $ch;
        }
        $dstPtr = $context->builder->gep($bufPtr, $idx);
        $context->builder->store($folded, $dstPtr);
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxAlloca
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($after);
    }

    /**
     * @return list<array{0: string, 1: string}> list of [classLc, targetLc]
     */
    private static function positivePairs(Context $context, string $kindLc): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        $add = static function (string $classDisplay, string $targetDisplay) use (&$seen): void {
            $classLc = strtolower(ltrim($classDisplay, '\\'));
            $targetLc = strtolower(ltrim($targetDisplay, '\\'));
            if ('' === $classLc || '' === $targetLc) {
                return;
            }
            $seen[$classLc."\0".$targetLc] = true;
        };

        $object = $context->type->object;
        $namesById = $object->allClassNamesById();
        foreach ($namesById as $classId => $className) {
            $classId = (int) $classId;
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display) {
                continue;
            }
            $classLc = strtolower(ltrim($display, '\\'));
            if ('implementsinterface' === $kindLc) {
                foreach ($object->allInterfacesForClassLc($classLc) as $ifaceLc) {
                    $add($display, $ifaceLc);
                }
                // Interface reflecting itself-as-parent chain via class_implements shape.
                foreach ($object->interfacesForClassImplementsLc($classLc) as $ifaceLc) {
                    $add($display, $ifaceLc);
                }
            } else { // issubclassof — strict subclass OR implements (Zend instanceof_function)
                foreach ($namesById as $otherId => $otherName) {
                    $otherId = (int) $otherId;
                    $otherDisplay = $object->classNameForId($otherId);
                    if (!\is_string($otherDisplay) || '' === $otherDisplay) {
                        $otherDisplay = \is_string($otherName) ? $otherName : '';
                    }
                    if ('' === $otherDisplay) {
                        continue;
                    }
                    if ($object->classIsSubclassOf($display, $otherDisplay)) {
                        $add($display, $otherDisplay);
                    }
                }
            }
        }

        $out = [];
        foreach (array_keys($seen) as $key) {
            $parts = explode("\0", $key, 2);
            if (2 === \count($parts)) {
                $out[] = [$parts[0], $parts[1]];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $out;
    }
}
