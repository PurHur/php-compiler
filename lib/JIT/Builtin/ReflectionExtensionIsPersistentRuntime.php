<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionExtension::isPersistent() (#34154).
 *
 * Bake loaded-extension lowercase names → true (MODULE_PERSISTENT / no dl()).
 * Thin AOT {@see StringInfo} / `__compiler_extension_loaded` returns 0 under
 * `PHP_COMPILER_HELPER_RUNTIME_O=0`, so a memcmp table is required.
 *
 * Peer fold+memcmp: {@see ReflectionClassIsFinalRuntime} (#34043).
 * VM: {@see \PHPCompiler\ext\standard\VmReflection::reflectionExtensionIsPersistent}.
 *
 * php-src: zim_ReflectionExtension_isPersistent
 */
final class ReflectionExtensionIsPersistentRuntime
{
    private const ABI = '__phpc_refl_ext_is_persistent';

    private const MAX_NAME_LEN = 512;

    /** @return Value i1 — true when extension name is loaded */
    public static function invoke(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $nameCstr,
            $nameLen
        );
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

        $entry = $fn->appendBasicBlock('refl_ext_isp_entry');
        $fold = $fn->appendBasicBlock('refl_ext_isp_fold');
        $trueBlock = $fn->appendBasicBlock('refl_ext_isp_yes');
        $falseBlock = $fn->appendBasicBlock('refl_ext_isp_no');

        $context->builder->positionAtEnd($entry);
        $nameCstr = $fn->getParam(0);
        $nameLen = $fn->getParam(1);
        $buf = $context->builder->alloca($i8->arrayType(self::MAX_NAME_LEN));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt(self::MAX_NAME_LEN, false)
        );
        $context->builder->branchIf($tooLong, $falseBlock, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock('refl_ext_isp_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_ext_isp_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_ext_isp_fold_body');
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

        $loaded = self::loadedExtensionLcNames();
        $checkBlock = $afterFold;
        $n = \count($loaded);
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($falseBlock);
        } else {
            foreach ($loaded as $idxName => $lcName) {
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
                $nameEq = $context->builder->icmp(
                    Builder::INT_EQ,
                    $cmp,
                    $i32->constInt(0, false)
                );
                $match = $context->builder->and($lenEq, $nameEq);
                $next = ($idxName === $n - 1)
                    ? $falseBlock
                    : $fn->appendBasicBlock('refl_ext_isp_try_'.($idxName + 1));
                $context->builder->branchIf($match, $trueBlock, $next);
                $checkBlock = $next;
            }
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

    /** @return list<string> lowercase loaded extension names */
    private static function loadedExtensionLcNames(): array
    {
        $out = [];
        foreach (ModuleRegistry::getLoadedExtensions() as $name) {
            $lc = strtolower((string) $name);
            if ('' === $lc) {
                continue;
            }
            $out[$lc] = $lc;
        }
        $names = array_values($out);
        sort($names, \SORT_STRING);

        return $names;
    }
}
