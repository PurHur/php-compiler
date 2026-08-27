<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Builtin\MbConvertVariablesRuntime;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\MbConvertVariablesLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_convert_variables() (php-src ext/mbstring/mbstring.c; #35315 leftover #4572).
 *
 * String/array/object by-ref via NestedJIT {@see MbConvertVariablesJitHelper}.
 */
final class JitMbConvertVariables
{
    private static int $seq = 0;

    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 3) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                sprintf('mb_convert_variables() expects at least 3 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_variables_argc');

            return self::foldFalse($context);
        }

        $fromCsv = self::compileTimeFromCsv($args[1]);
        if (null === $fromCsv && self::isArrayArg($args[1])) {
            throw new \LogicException(
                'mb_convert_variables() array $from_encoding is not lowered for JIT/AOT runtime in this compiler build'
            );
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertVariablesRuntime::ensureLinked($context);
        MbConvertEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_variables_runtime');

        [$toPtr, $toLit] = self::encodingPtr($context, $args[0], 'to_encoding', 0);
        if (null !== $toLit && !self::isLeafEncoding($toLit)) {
            if (null === CharsetEngine::parseEncodingSpec($toLit)
                && !VmMbstring::isMbConvertPseudoEncoding($toLit)
            ) {
                ExceptionBridge::ensureLinked($context);
                ExceptionBridge::emitValueErrorAndAbort(
                    $context,
                    'mb_convert_variables(): Argument #1 ($to_encoding) is not a supported encoding, "'.$toLit.'" given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_variables_to_err');

                return self::foldFalse($context);
            }
            throw new \LogicException(
                'mb_convert_variables() non-leaf $to_encoding is not lowered for JIT/AOT runtime in this compiler build'
            );
        }

        if (null !== $fromCsv) {
            foreach (explode(',', $fromCsv) as $fromPart) {
                if ('' === $fromPart) {
                    continue;
                }
                if (!self::isLeafEncoding($fromPart)) {
                    if (null === CharsetEngine::parseEncodingSpec($fromPart)
                        && !VmMbstring::isMbConvertPseudoEncoding($fromPart)
                    ) {
                        ExceptionBridge::ensureLinked($context);
                        ExceptionBridge::emitValueErrorAndAbort(
                            $context,
                            'mb_convert_variables(): Argument #2 ($from_encoding) contains invalid encoding "'.$fromPart.'"'
                        );
                        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_variables_from_err');

                        return self::foldFalse($context);
                    }
                    throw new \LogicException(
                        'mb_convert_variables() non-leaf $from_encoding is not lowered for JIT/AOT runtime in this compiler build'
                    );
                }
            }
            $fromPtr = $context->builder->load($context->constantStringFromString($fromCsv));
        } else {
            [$fromPtr, $fromLit] = self::encodingPtr($context, $args[1], 'from_encoding', 1);
            if (null !== $fromLit && !self::isLeafEncoding($fromLit)) {
                throw new \LogicException(
                    'mb_convert_variables() non-leaf $from_encoding is not lowered for JIT/AOT runtime in this compiler build'
                );
            }
        }

        $strPtrTy = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');

        $lastDetectedSlot = BasicBlockHelper::entryAlloca($context, $strPtrTy);
        $context->builder->store(
            $context->builder->load($context->constantStringFromString('')),
            $lastDetectedSlot
        );
        $anyFailSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $anyFailSlot);

        for ($i = 2; $i < $argc; ++$i) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_variables_var_'.$i);
            if (self::isArrayArg($args[$i])) {
                self::lowerArrayVar($context, $args[$i], $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
            } elseif (self::isObjectArg($args[$i])) {
                self::lowerObjectVar($context, $args[$i], $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
            } else {
                self::lowerStringVar($context, $args[$i], $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot, $i);
            }
        }

        $failed = $context->builder->load($anyFailSlot);
        $tag = 'mcv_r'.(++self::$seq);
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $i1->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $okHead = $context->builder->getInsertBlock();
        $outStr = $context->builder->load($lastDetectedSlot);
        $outLen = $context->builder->call($context->lookupFunction('__string__strlen'), $outStr);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $useFrom = $context->builder->icmp(Builder::INT_EQ, $outLen, $zero);
        $fromFallbackBlock = BasicBlockHelper::append($context, $tag.'_from_fb');
        $packBlock = BasicBlockHelper::append($context, $tag.'_pack');
        $context->builder->branchIf($useFrom, $fromFallbackBlock, $packBlock);
        $context->builder->positionAtEnd($fromFallbackBlock);
        $context->builder->branch($packBlock);
        $context->builder->positionAtEnd($packBlock);
        $outPhi = $context->builder->phi($strPtrTy, 'mcv_out_str');
        $outPhi->addIncoming($fromPtr, $fromFallbackBlock);
        $outPhi->addIncoming($outStr, $okHead);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $ownedOut = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $outPhi
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $okPtr, $ownedOut);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($okPtr, $okTail);

        return $result;
    }

    private static function lowerStringVar(
        Context $context,
        JITVariable $arg,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot,
        int $argIndex
    ): void {
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        // Resolve by-ref lvalue before NestedJIT helper calls (#35315 / peer #34270).
        if (null === $arg->valueBoxAliasPtr) {
            ClosureHelper::referenceCapture($context, $arg);
        }
        $outPtr = null !== $arg->valueBoxAliasPtr
            ? JitValueBox::normalizeValuePtr($context, $arg->valueBoxAliasPtr)
            : JitValueBox::valuePtrFromVariable($context, $arg);
        if (
            \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            && !$arg->functionStaticGlobal
            && null === $arg->valueBoxAliasPtr
        ) {
            throw new \LogicException(
                'mb_convert_variables(): by-ref $var is not a script-global lvalue (#35315)'
            );
        }

        $str = JitStringBuiltinArg::lower(
            $context,
            $arg,
            'mb_convert_variables',
            $argIndex,
            'var'
        );
        // Peer JitMbConvertEncoding::convertHelper — MbConvertVariablesJitHelper::convertStringArgv
        // duplicates convertArgv but NestedJIT link for convertStringHelper mis-returns (#35315).
        $converted = $context->builder->call(
            MbConvertEncodingRuntime::convertHelper($context),
            $str,
            $toPtr,
            $fromPtr
        );
        $detected = $context->builder->call(
            MbConvertVariablesRuntime::detectHelper($context),
            $str,
            $toPtr,
            $fromPtr
        );

        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $converted);
        $context->builder->call($context->lookupFunction('__value__writeString'), $outPtr, $owned);
        JitValueBox::publishAfterWrite($context, $outPtr);

        $dlen = $context->builder->call($context->lookupFunction('__string__strlen'), $detected);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $dlen, $zero);
        $tag = 'mcv_s'.(++self::$seq);
        $emptyBlock = BasicBlockHelper::append($context, $tag.'_empty');
        $keepBlock = BasicBlockHelper::append($context, $tag.'_keep');
        $contBlock = BasicBlockHelper::append($context, $tag.'_cont');
        $context->builder->branchIf($isEmpty, $emptyBlock, $keepBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store($i1->constInt(1, false), $anyFailSlot);
        $context->builder->branch($contBlock);

        $context->builder->positionAtEnd($keepBlock);
        $ownedDet = $context->builder->call($context->lookupFunction('__string__separate'), $detected);
        $context->builder->store($ownedDet, $lastDetectedSlot);
        $context->builder->branch($contBlock);

        $context->builder->positionAtEnd($contBlock);
    }

    private static function lowerArrayVar(
        Context $context,
        JITVariable $arg,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $arg);
        MbConvertVariablesLlvm::convertArrayInPlace(
            $context,
            $ht,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
    }

    private static function lowerObjectVar(
        Context $context,
        JITVariable $arg,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        MbConvertVariablesLlvm::convertObjectInPlace(
            $context,
            $obj,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
    }

    /**
     * @return array{0: Value, 1: ?string}
     */
    private static function encodingPtr(Context $context, JITVariable $arg, string $name, int $argIndex): array
    {
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            if (VmMbstring::isMbConvertPseudoEncoding($lit)) {
                throw new \LogicException(
                    'mb_convert_variables() pseudo encodings are not lowered for JIT/AOT runtime in this compiler build'
                );
            }

            return [$context->builder->load($context->constantStringFromString($lit)), $lit];
        }

        return [
            JitStringBuiltinArg::lower($context, $arg, 'mb_convert_variables', $argIndex, $name),
            null,
        ];
    }

    private static function isLeafEncoding(string $encoding): bool
    {
        $e = strtoupper($encoding);

        return 'UTF8' === $e || 'UTF-8' === $e
            || 'LATIN1' === $e || 'LATIN-1' === $e || 'ISO-8859-1' === $e
            || 'ASCII' === $e || 'US-ASCII' === $e;
    }

    private static function compileTimeFromCsv(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }
        $arr = $arg->compileTimeArray ?? null;
        if (null === $arr) {
            return null;
        }
        $parts = [];
        foreach ($arr as $elem) {
            if (\is_string($elem)) {
                $parts[] = $elem;
            } elseif ($elem instanceof JITVariable) {
                $s = JitStringArg::compileTimeLiteral($elem);
                if (null === $s) {
                    return null;
                }
                $parts[] = $s;
            } else {
                return null;
            }
        }

        return implode(',', $parts);
    }

    private static function isObjectArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return true;
        }
        $classHint = ltrim((string) ($arg->classUserType ?? ''), '\\');
        if ('' !== $classHint && 'mixed' !== strtolower($classHint)) {
            return true;
        }

        return false;
    }

    private static function isArrayArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || (($arg->type & JITVariable::IS_NATIVE_ARRAY) !== 0)
            || ($arg->compileTimeEmptyArrayLiteral ?? false)
            || null !== ($arg->compileTimeArray ?? null);
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
