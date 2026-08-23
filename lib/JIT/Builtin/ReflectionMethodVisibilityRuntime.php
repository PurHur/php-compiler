<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\MethodVisibility;
use PHPCfg\Func as CfgFunc;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT name tables for ReflectionMethod::isPublic / isStatic (#34216).
 *
 * Unbound ExternalMethod previously returned NULL. Bake class+method pairs from
 * {@see Type\Object_::methodVisibility} (peer {@see ReflectionPropertyIsFinalRuntime}).
 *
 * php-src: zim_ReflectionMethod_isPublic / zim_ReflectionFunctionAbstract_isStatic
 */
final class ReflectionMethodVisibilityRuntime
{
    /** @var array<string, string> kindLc => ABI */
    private const ABI = [
        'ispublic' => '__phpc_refl_method_is_public',
        'isstatic' => '__phpc_refl_method_is_static',
    ];

    /**
     * @param 'isPublic'|'isStatic' $kind
     */
    public static function invoke(
        Context $context,
        string $kind,
        Value $classCstr,
        Value $classLen,
        Value $methodCstr,
        Value $methodLen
    ): Value {
        $kindLc = strtolower($kind);
        if (!isset(self::ABI[$kindLc])) {
            throw new \InvalidArgumentException('Unknown ReflectionMethod visibility kind: '.$kind);
        }
        self::ensureLinked($context, $kindLc);

        return $context->builder->call(
            $context->lookupFunction(self::ABI[$kindLc]),
            $classCstr,
            $classLen,
            $methodCstr,
            $methodLen
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
            throw new \InvalidArgumentException('Unknown ReflectionMethod visibility kind: '.$kindLc);
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

        $tag = 'refl_m_'.$kindLc;
        $entry = $fn->appendBasicBlock($tag.'_entry');
        $context->builder->positionAtEnd($entry);
        $classArg = $fn->getParam(0);
        $classLenArg = $fn->getParam(1);
        $methodArg = $fn->getParam(2);
        $methodLenArg = $fn->getParam(3);

        $trueBlock = $fn->appendBasicBlock($tag.'_yes');
        $falseBlock = $fn->appendBasicBlock($tag.'_no');

        $maxLen = 512;
        $classBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $classBufPtr = $context->builder->pointerCast($classBuf, $i8p);
        $methodBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $methodBufPtr = $context->builder->pointerCast($methodBuf, $i8p);

        $maxConst = $sizeT->constInt($maxLen, false);
        $tooLong = $context->builder->or(
            $context->builder->icmp(Builder::INT_UGT, $classLenArg, $maxConst),
            $context->builder->icmp(Builder::INT_UGT, $methodLenArg, $maxConst)
        );
        $foldClass = $fn->appendBasicBlock($tag.'_fold_class');
        $context->builder->branchIf($tooLong, $falseBlock, $foldClass);

        $foldMethod = $fn->appendBasicBlock($tag.'_fold_method');
        $afterFold = $fn->appendBasicBlock($tag.'_after_fold');
        self::emitAsciiFold(
            $context,
            $fn,
            $foldClass,
            $tag.'_class',
            $classArg,
            $classLenArg,
            $classBufPtr,
            $sizeT,
            $i8,
            $foldMethod
        );
        self::emitAsciiFold(
            $context,
            $fn,
            $foldMethod,
            $tag.'_method',
            $methodArg,
            $methodLenArg,
            $methodBufPtr,
            $sizeT,
            $i8,
            $afterFold
        );

        $pairs = self::collectMatchingPairs($context, $kindLc);
        $strMap = $context->structFieldMap['__string__'];
        $checkBlock = $afterFold;
        $n = \count($pairs);
        foreach ($pairs as $idx => [$classLc, $methodLcName]) {
            $context->builder->positionAtEnd($checkBlock);
            $wantClassLen = $sizeT->constInt(\strlen($classLc), false);
            $wantMethodLen = $sizeT->constInt(\strlen($methodLcName), false);

            $wantClassStr = $context->builder->load($context->constantStringFromString($classLc));
            $wantClassCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantClassStr, $strMap['value']),
                $i8p
            );
            $wantMethodStr = $context->builder->load($context->constantStringFromString($methodLcName));
            $wantMethodCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantMethodStr, $strMap['value']),
                $i8p
            );

            $classEq = $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $classLenArg, $wantClassLen),
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->call(
                        $context->lookupFunction('memcmp'),
                        $classBufPtr,
                        $wantClassCstr,
                        $context->builder->zExt($wantClassLen, $i64)
                    ),
                    $i32->constInt(0, false)
                )
            );
            $methodEq = $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $methodLenArg, $wantMethodLen),
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->call(
                        $context->lookupFunction('memcmp'),
                        $methodBufPtr,
                        $wantMethodCstr,
                        $context->builder->zExt($wantMethodLen, $i64)
                    ),
                    $i32->constInt(0, false)
                )
            );
            $both = $context->builder->and($classEq, $methodEq);
            $next = ($idx === $n - 1)
                ? $falseBlock
                : $fn->appendBasicBlock($tag.'_try_'.($idx + 1));
            $context->builder->branchIf($both, $trueBlock, $next);
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
     * @param mixed $fn
     * @param mixed $startBlock
     * @param mixed $sizeT
     * @param mixed $i8
     * @param mixed $cont
     */
    private static function emitAsciiFold(
        Context $context,
        $fn,
        $startBlock,
        string $prefix,
        Value $src,
        Value $len,
        Value $bufPtr,
        $sizeT,
        $i8,
        $cont
    ): void {
        $context->builder->positionAtEnd($startBlock);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($prefix.'_fold_loop');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $len);
        $body = $fn->appendBasicBlock($prefix.'_fold_body');
        $context->builder->branchIf($done, $cont, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($src, $idx);
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
    }

    /**
     * @return list<array{0: string, 1: string}> lowercase class + method pairs that match $kindLc
     */
    private static function collectMatchingPairs(Context $context, string $kindLc): array
    {
        $object = $context->type->object;
        $pairs = [];
        foreach ($object->allClassNamesById() as $classId => $_) {
            $display = $object->classNameForId((int) $classId);
            if (!\is_string($display) || '' === $display) {
                continue;
            }
            $classLc = strtolower($display);
            if (str_starts_with($classLc, 'reflection')) {
                continue;
            }
            foreach ($object->declaredMethodNames((int) $classId) as $methodLc) {
                $flags = $object->methodVisibility((int) $classId, $methodLc);
                $match = match ($kindLc) {
                    'ispublic' => MethodVisibility::isPublic($flags),
                    'isstatic' => (0 !== ($flags & CfgFunc::FLAG_STATIC)),
                    default => false,
                };
                if ($match) {
                    $pairs[] = [$classLc, strtolower((string) $methodLc)];
                }
            }
        }

        return $pairs;
    }
}
