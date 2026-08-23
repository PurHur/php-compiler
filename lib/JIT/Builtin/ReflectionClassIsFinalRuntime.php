<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionClass::isFinal() (#18297, #28135, #34043).
 *
 * Thin AOT previously used {@see StringCaseCompare}, which always matches the
 * first table entry under thin AOT — so every class reported isFinal()===true.
 * Compile-unit + builtin names are matched with length-checked {@see memcmp} on
 * lowercase spellings (peer of #34027 / #34032).
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_isFinal
 * (ce->ce_flags & ZEND_ACC_FINAL; enums are final).
 */
final class ReflectionClassIsFinalRuntime
{
    private const ABI = '__phpc_refl_class_is_final';

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

        $entry = $fn->appendBasicBlock('refl_class_is_final_entry');
        $fold = $fn->appendBasicBlock('refl_class_is_final_fold');
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
        $trueBlock = $fn->appendBasicBlock('refl_class_final_yes');
        $falseBlock = $fn->appendBasicBlock('refl_class_final_no');
        // Oversize names cannot match a known final → false.
        $context->builder->branchIf($tooLong, $falseBlock, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock('refl_isf_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_isf_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_isf_fold_body');
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
        $finals = self::finalLowerNames($context);
        $checkBlock = $afterFold;
        $n = \count($finals);
        foreach ($finals as $idxName => $lcName) {
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
                : $fn->appendBasicBlock('refl_isf_try_'.($idxName + 1));
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
     * Builtin ZEND_ACC_FINAL + compile-unit finals / enums (lowercase).
     *
     * @return list<string>
     */
    private static function finalLowerNames(Context $context): array
    {
        $seen = [];
        $add = static function (string $display) use (&$seen): void {
            $lc = strtolower(ltrim($display, '\\'));
            if ('' !== $lc) {
                $seen[$lc] = true;
            }
        };

        foreach (self::knownFinalClassNames() as $builtin) {
            $add($builtin);
        }

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
            if ($object->isFinalClassLc($lc) || $object->isEnumClassLc($lc)) {
                $add($display);
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry) {
                    continue;
                }
                if (ReflectionSupport::reflectionClassIsFinal($entry)) {
                    $add($entry->name);
                }
            }
        }

        $out = array_keys($seen);
        sort($out);

        return $out;
    }

    /**
     * Builtin ZEND_ACC_FINAL class names (php-src stubs / zend_inheritance.c).
     *
     * @return list<string>
     */
    private static function knownFinalClassNames(): array
    {
        $names = [
            'AddressInfo',
            'AllowDynamicProperties',
            'Attribute',
            'Closure',
            'DeflateContext',
            'Fiber',
            'FiberError',
            'Generator',
            // php-src ext/hash/hash.stub.php — final class HashContext (#28384)
            'HashContext',
            'InflateContext',
            // php-src ext/openssl/openssl.stub.php — final OpenSSL object classes (#28370)
            'OpenSSLAsymmetricKey',
            'OpenSSLCertificate',
            'OpenSSLCertificateSigningRequest',
            'Random\\Engine\\Mt19937',
            'Random\\Engine\\PcgOneseq128XslRr64',
            'Random\\Engine\\Secure',
            'Random\\Engine\\Xoshiro256StarStar',
            'Random\\Randomizer',
            'ReturnTypeWillChange',
            'SensitiveParameter',
            'Socket',
            // php-src ext/xml/xml.stub.php — final class XMLParser (#28386)
            'XMLParser',
        ];
        // php-src 8.4+ final class GMP (ext/gmp/gmp.stub.php; #28135)
        if (CompilerVersion::supportsGmp()) {
            $names[] = 'GMP';
        }
        // php-src Zend/zend_attributes.stub.php — profile-gated finals (#28402)
        if (CompilerVersion::advertisesOverrideAttributeClass()) {
            $names[] = 'Override';
        }
        if (CompilerVersion::advertisesDeprecatedAttributeClass()) {
            $names[] = 'Deprecated';
        }
        if (CompilerVersion::advertisesNoDiscardAttributeClass()) {
            $names[] = 'NoDiscard';
        }

        return $names;
    }
}
