<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT (class, member) tables for ReflectionClass::hasMethod/hasProperty/hasConstant (#34072).
 *
 * Unbound proxies previously returned NULL. Compile-unit lowercase class + member
 * memcmp tables (methods case-folded; properties/constants exact) match Zend.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_has*
 */
final class ReflectionClassHasMemberRuntime
{
    /** @var array<string, string> kind => ABI */
    private const ABI = [
        'hasmethod' => '__phpc_refl_class_has_method',
        'hasproperty' => '__phpc_refl_class_has_property',
        'hasconstant' => '__phpc_refl_class_has_constant',
    ];

    public static function invoke(
        Context $context,
        string $kind,
        Value $classCstr,
        Value $classLen,
        Value $memberCstr,
        Value $memberLen,
    ): Value {
        $kindLc = strtolower($kind);
        if (!isset(self::ABI[$kindLc])) {
            throw new \InvalidArgumentException('Unknown ReflectionClass has* kind: '.$kind);
        }
        self::ensureLinked($context, $kindLc);

        return $context->builder->call(
            $context->lookupFunction(self::ABI[$kindLc]),
            $classCstr,
            $classLen,
            $memberCstr,
            $memberLen
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
            throw new \InvalidArgumentException('Unknown ReflectionClass has* kind: '.$kindLc);
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
        $foldMember = $fn->appendBasicBlock($tag.'_fold_member');
        $trueBlock = $fn->appendBasicBlock($tag.'_yes');
        $falseBlock = $fn->appendBasicBlock($tag.'_no');

        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $classLen = $fn->getParam(1);
        $memberCstr = $fn->getParam(2);
        $memberLen = $fn->getParam(3);

        $maxLen = 512;
        $classBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $classBufPtr = $context->builder->pointerCast($classBuf, $i8p);
        $memberBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $memberBufPtr = $context->builder->pointerCast($memberBuf, $i8p);

        $classTooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $classLen,
            $sizeT->constInt($maxLen, false)
        );
        $memberTooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $memberLen,
            $sizeT->constInt($maxLen, false)
        );
        $tooLong = $context->builder->or($classTooLong, $memberTooLong);
        $context->builder->branchIf($tooLong, $falseBlock, $foldClass);

        // Fold class name to lowercase.
        self::emitFoldAscii(
            $context,
            $fn,
            $tag.'_c',
            $foldClass,
            $foldMember,
            $classCstr,
            $classLen,
            $classBufPtr,
            $sizeT,
            $i8,
            true
        );

        // Methods: fold member. Properties/constants: exact copy.
        $foldMemberName = 'hasmethod' === $kindLc;
        self::emitFoldAscii(
            $context,
            $fn,
            $tag.'_m',
            $foldMember,
            null,
            $memberCstr,
            $memberLen,
            $memberBufPtr,
            $sizeT,
            $i8,
            $foldMemberName
        );
        // emitFoldAscii with null afterBlock leaves builder at after-fold; continue checks there.
        $afterFold = $context->builder->getInsertBlock();

        $pairs = self::positivePairs($context, $kindLc);
        $checkBlock = $afterFold;
        $n = \count($pairs);
        foreach ($pairs as $idx => $pair) {
            [$classLc, $memberKey] = $pair;
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

            $wantMemberLen = $sizeT->constInt(\strlen($memberKey), false);
            $wantMemberGlobal = $context->constantStringFromString($memberKey);
            $wantMemberStr = $context->builder->load($wantMemberGlobal);
            $wantMemberCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantMemberStr, $strMap['value']),
                $i8p
            );
            $memberLenEq = $context->builder->icmp(Builder::INT_EQ, $memberLen, $wantMemberLen);
            $memberCmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $memberBufPtr,
                $wantMemberCstr,
                $context->builder->zExt($wantMemberLen, $i64)
            );
            $memberEq = $context->builder->icmp(Builder::INT_EQ, $memberCmp, $i32->constInt(0, false));

            $match = $context->builder->and(
                $context->builder->and($classLenEq, $classEq),
                $context->builder->and($memberLenEq, $memberEq)
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
     * @return list<array{0: string, 1: string}> list of [classLc, memberKey]
     */
    private static function positivePairs(Context $context, string $kindLc): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        $add = static function (string $classDisplay, string $memberKey) use (&$seen, $kindLc): void {
            $classLc = strtolower(ltrim($classDisplay, '\\'));
            if ('' === $classLc || '' === $memberKey) {
                return;
            }
            if ('hasmethod' === $kindLc) {
                $memberKey = strtolower($memberKey);
            }
            $seen[$classLc."\0".$memberKey] = true;
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
            $currentId = $classId;
            for ($depth = 0; $depth < 64; ++$depth) {
                if ('hasmethod' === $kindLc) {
                    foreach ($object->declaredMethodNames($currentId) as $methodLc) {
                        $add($display, $methodLc);
                    }
                } elseif ('hasproperty' === $kindLc) {
                    foreach ($object->propertySetsForClass($currentId) as $propset) {
                        if (isset($propset[1]) && \is_string($propset[1]) && '' !== $propset[1]) {
                            // Skip compiler-private storage props that Zend omits (#22513).
                            if (str_starts_with($propset[1], '__')) {
                                continue;
                            }
                            $add($display, $propset[1]);
                        }
                    }
                } else { // hasconstant
                    foreach ($object->classConstantNamesForId($currentId) as $constName) {
                        $add($display, $constName);
                    }
                }
                $parentLc = $object->parentClassLc($object->classNameForId($currentId));
                if (null === $parentLc) {
                    break;
                }
                $parentId = $object->classIdForLowerName($parentLc);
                if (null === $parentId) {
                    break;
                }
                $currentId = $parentId;
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!\is_object($entry) || !isset($entry->name)) {
                    continue;
                }
                $name = (string) $entry->name;
                if ('hasmethod' === $kindLc) {
                    foreach (VmReflection::collectClassMethodsForReflection($entry, $vmCtx, 0) as $spec) {
                        if (isset($spec['methodLc']) && \is_string($spec['methodLc'])) {
                            $add($name, $spec['methodLc']);
                        }
                    }
                    if (VmReflection::isClosureInvokeMethod($name, '__invoke')) {
                        $add($name, '__invoke');
                    }
                } elseif ('hasproperty' === $kindLc) {
                    foreach (VmReflection::collectClassPropertiesForReflection($entry, $vmCtx, 0) as $prop) {
                        if (isset($prop->name) && \is_string($prop->name) && !$prop->phpInvisible) {
                            $add($name, $prop->name);
                        }
                    }
                } else {
                    foreach (VmReflection::collectClassConstantsForReflection($entry, $vmCtx, 0) as $const) {
                        if (isset($const['name']) && \is_string($const['name'])) {
                            $add($name, $const['name']);
                        }
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
