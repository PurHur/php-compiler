<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\ext\standard\VmReflection;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT name table for ReflectionClass::getConstructor() (#34073).
 *
 * Returns the declaring-class display name as {@see __string__*} when the
 * reflected class (or an ancestor) declares {@see __construct}; otherwise null.
 * Peer of {@see ReflectionClassIsCloneableRuntime} memcmp+fold tables.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_getConstructor
 */
final class ReflectionClassGetConstructorRuntime
{
    private const ABI = '__phpc_refl_class_get_constructor_declaring';

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
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_class_getctor_entry');
        $fold = $fn->appendBasicBlock('refl_class_getctor_fold');
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
        $missBlock = $fn->appendBasicBlock('refl_class_getctor_miss');
        $context->builder->branchIf($tooLong, $missBlock, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock('refl_class_getctor_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_class_getctor_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_class_getctor_fold_body');
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
        $pairs = self::constructorDeclaringPairs($context);
        $checkBlock = $afterFold;
        $n = \count($pairs);
        $strMap = $context->structFieldMap['__string__'];
        foreach ($pairs as $idxPair => [$reflectedLc, $declaringDisplay]) {
            $context->builder->positionAtEnd($checkBlock);
            $wantLenInt = \strlen($reflectedLc);
            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($reflectedLc);
            $wantStr = $context->builder->load($wantGlobal);
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
            $hit = $fn->appendBasicBlock('refl_class_getctor_hit_'.$idxPair);
            $next = ($idxPair === $n - 1)
                ? $missBlock
                : $fn->appendBasicBlock('refl_class_getctor_try_'.($idxPair + 1));
            $context->builder->branchIf($match, $hit, $next);

            $context->builder->positionAtEnd($hit);
            $declStr = $context->builder->load(
                $context->constantStringFromString($declaringDisplay)
            );
            $context->builder->returnValue($declStr);
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($missBlock);
        }

        $context->builder->positionAtEnd($missBlock);
        $context->builder->returnValue($strPtr->constNull());

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @return list<array{0: string, 1: string}> [reflectedLc, declaringDisplay]
     */
    private static function constructorDeclaringPairs(Context $context): array
    {
        $byLc = [];
        $add = static function (string $reflectedDisplay, string $declaringDisplay) use (&$byLc): void {
            $lc = strtolower(ltrim($reflectedDisplay, '\\'));
            $decl = ltrim($declaringDisplay, '\\');
            if ('' === $lc || '' === $decl) {
                return;
            }
            $byLc[$lc] = $decl;
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
            $declaring = self::objectConstructorDeclaringDisplay($object, $classId);
            if (null !== $declaring) {
                $add($display, $declaring);
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if ($vmCtx instanceof VmContext && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry) {
                    continue;
                }
                $declaring = self::vmConstructorDeclaringDisplay($entry, $vmCtx);
                if (null !== $declaring) {
                    $add((string) $entry->name, $declaring);
                }
            }
        }

        // Common builtins with a real __construct (php-src ce->constructor).
        foreach ([
            'Exception' => 'Exception',
            'Error' => 'Error',
            'ErrorException' => 'ErrorException',
            'DateTime' => 'DateTime',
            'DateTimeImmutable' => 'DateTimeImmutable',
            'DateTimeZone' => 'DateTimeZone',
            'DateInterval' => 'DateInterval',
            'DatePeriod' => 'DatePeriod',
            'ReflectionClass' => 'ReflectionClass',
            'ReflectionObject' => 'ReflectionObject',
            'ReflectionMethod' => 'ReflectionMethod',
            'ReflectionFunction' => 'ReflectionFunction',
            'ReflectionProperty' => 'ReflectionProperty',
            'ReflectionParameter' => 'ReflectionParameter',
            'Closure' => 'Closure',
        ] as $reflected => $declaring) {
            $add($reflected, $declaring);
        }

        $out = [];
        foreach ($byLc as $lc => $decl) {
            $out[] = [$lc, $decl];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $out;
    }

    private static function objectConstructorDeclaringDisplay(
        Type\Object_ $object,
        int $classId,
    ): ?string {
        // Use hasConstructor() (declared only) — hasMethod() includes inherited
        // visibility copies and would attribute parent ctors to the child (#34073).
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            if ($object->hasConstructor($currentId)) {
                $name = $object->classNameForId($currentId);

                return \is_string($name) && '' !== $name ? $name : null;
            }
            $currentName = $object->classNameForId($currentId);
            if (!\is_string($currentName) || '' === $currentName) {
                break;
            }
            $parentLc = $object->parentClassLc($currentName);
            if (null === $parentLc) {
                break;
            }
            $parentId = $object->classIdForLowerName($parentLc);
            if (null === $parentId) {
                break;
            }
            $currentId = $parentId;
        }

        return null;
    }

    private static function vmConstructorDeclaringDisplay(
        ClassEntry $entry,
        VmContext $ctx,
    ): ?string {
        foreach (VmReflection::classHierarchyChain($entry, $ctx) as $class) {
            if (!$class instanceof ClassEntry) {
                continue;
            }
            if (isset($class->methods['__construct'])) {
                $name = (string) $class->name;

                return '' !== $name ? $name : null;
            }
        }

        return null;
    }
}
