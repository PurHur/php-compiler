<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\MbConvertEncodingArrayLlvm;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_convert_encoding() (php-src ext/mbstring/mbstring.c; #6251, #34309).
 *
 * Compile-time fold for string literals; runtime string + encodings via NestedJIT
 * {@see MbConvertEncodingJitHelper} (#35165 leftover of #34309 / peer #35161).
 * Array / comma-list `$from_encoding` folds via {@see VmMbstring::convertEncodingWithFromList}
 * (#35296 leftover of #23562) — do not lower hashtable args as strings (SEGV).
 */
final class JitMbConvertEncoding
{
    /**
     * @param list<JITVariable> $args  Arity + null-$string already handled by caller
     */
    public static function invoke(Context $context, array $args, bool $sourceIsNull): Value
    {
        $argc = \count($args);

        $folded = self::tryCompileTimeFold($context, $args, $argc, $sourceIsNull);
        if (null !== $folded) {
            return $folded;
        }

        $fromIsDefault = 2 === $argc
            || (
                3 === $argc
                && (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant)
            );

        if (self::isArrayArg($args[0])) {
            $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
            MbConvertEncodingRuntime::ensureLinked($context);
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_encoding_array_runtime');

            [$toPtr, $assertTo] = self::toEncodingPtr($context, $args[1]);
            if ($assertTo) {
                $context->builder->call(
                    MbConvertEncodingRuntime::assertToEncodingHelper($context),
                    $toPtr
                );
            }

            if ($fromIsDefault) {
                $fromLit = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
                $fromPtr = $context->builder->load($context->constantStringFromString($fromLit));
            } else {
                [$fromPtr, $assertFrom] = self::fromEncodingPtr($context, $args[2]);
                if ($assertFrom) {
                    $context->builder->call(
                        MbConvertEncodingRuntime::assertFromEncodingHelper($context),
                        $fromPtr
                    );
                }
            }

            return MbConvertEncodingArrayLlvm::convert($context, $args[0], $toPtr, $fromPtr);
        }

        // Runtime / non-literal array $from_encoding — never JitStringBuiltinArg::lower (#35296).
        if (!$fromIsDefault && self::isArrayArg($args[2])) {
            throw new \LogicException(
                'mb_convert_encoding() array $from_encoding is not lowered for JIT/AOT runtime in this compiler build'
            );
        }

        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #35165).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_encoding_runtime');

        // Soft-null DEP already emitted by caller; NestedJIT recovers '' (#21282).
        $str = $sourceIsNull
            ? $context->builder->load($context->constantStringFromString(''))
            : JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                'mb_convert_encoding',
                0,
                'string'
            );

        [$toPtr, $assertTo] = self::toEncodingPtr($context, $args[1]);
        if ($assertTo) {
            $context->builder->call(
                MbConvertEncodingRuntime::assertToEncodingHelper($context),
                $toPtr
            );
        }

        if ($fromIsDefault) {
            $fromLit = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            // Always pass resolved from_encoding — NestedJIT of MbstringState::internalEncoding aborts.
            $fromPtr = $context->builder->load($context->constantStringFromString($fromLit));
        } else {
            [$fromPtr, $assertFrom] = self::fromEncodingPtr($context, $args[2]);
            if ($assertFrom) {
                $context->builder->call(
                    MbConvertEncodingRuntime::assertFromEncodingHelper($context),
                    $fromPtr
                );
            }
        }

        $resultStr = $context->builder->call(
            MbConvertEncodingRuntime::convertHelper($context),
            $str,
            $toPtr,
            $fromPtr
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * Literal leaf encoding → constant string (no assert); otherwise NestedJIT + assert (#35165).
     *
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function toEncodingPtr(Context $context, JITVariable $arg): array
    {
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $encodingLit) {
            if (VmMbstring::isMbConvertPseudoEncoding($encodingLit)) {
                throw new \LogicException(
                    'mb_convert_encoding() pseudo encodings are not lowered for JIT/AOT runtime in this compiler build'
                );
            }
            if (self::isLeafEncoding($encodingLit)) {
                return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
            }
            if (null === CharsetEngine::parseEncodingSpec($encodingLit)) {
                // Known-invalid literal: assert → Zend ValueError (#35165).
                return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
            }
            // Non-leaf but CharsetEngine-valid (e.g. UTF-16): NestedJIT leaf returns ''.
            return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $arg,
                'mb_convert_encoding',
                1,
                'to_encoding'
            ),
            true,
        ];
    }

    /**
     * @return array{0: Value, 1: bool}
     */
    private static function fromEncodingPtr(Context $context, JITVariable $arg): array
    {
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $encodingLit) {
            if (VmMbstring::isMbConvertPseudoEncoding($encodingLit)) {
                throw new \LogicException(
                    'mb_convert_encoding() pseudo encodings are not lowered for JIT/AOT runtime in this compiler build'
                );
            }
            if (str_contains($encodingLit, ',')) {
                throw new \LogicException(
                    'mb_convert_encoding() detect-then-convert from_encoding lists are not lowered for JIT/AOT runtime in this compiler build'
                );
            }
            if (self::isLeafEncoding($encodingLit)) {
                return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
            }
            if (null === CharsetEngine::parseEncodingSpec($encodingLit)) {
                return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
            }

            return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $arg,
                'mb_convert_encoding',
                2,
                'from_encoding'
            ),
            true,
        ];
    }

    private static function isLeafEncoding(string $encoding): bool
    {
        $e = strtoupper($encoding);

        return 'UTF8' === $e || 'UTF-8' === $e
            || 'LATIN1' === $e || 'LATIN-1' === $e || 'ISO-8859-1' === $e
            || 'ASCII' === $e || 'US-ASCII' === $e;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFold(
        Context $context,
        array $args,
        int $argc,
        bool $sourceIsNull
    ): ?Value {
        $toLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        $fromList = self::compileTimeFromEncodingList($context, $args, $argc);
        if (null === $toLit || null === $fromList) {
            return null;
        }
        if ([] === $fromList) {
            return self::emitFromEncodingValueError(
                $context,
                'mb_convert_encoding(): Argument #3 ($from_encoding) must specify at least one encoding'
            );
        }
        foreach ($fromList as $from) {
            if (!self::isValidMbConvertEncodingName($from)) {
                return self::emitFromEncodingValueError(
                    $context,
                    'mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding "'.$from.'"'
                );
            }
        }
        if (!self::isValidMbConvertEncodingName($toLit)) {
            return self::foldFalse($context);
        }

        $sourceLit = $sourceIsNull ? '' : JitStringBuiltinArg::compileTimeLiteral($args[0]);
        if (null !== $sourceLit) {
            $converted = VmMbstring::convertEncodingWithFromList($sourceLit, $toLit, $fromList);
            if (false === $converted) {
                return self::foldFalse($context);
            }

            return self::foldString($context, $converted);
        }

        $sourceHt = self::compileTimeSourceHashTable($context, $args[0]);
        if (null !== $sourceHt) {
            $result = VmMbstring::convertEncodingSourceArray($sourceHt, $toLit, $fromList);
            if (false === $result) {
                return self::foldFalse($context);
            }

            return self::foldHashTable($context, $result);
        }

        return null;
    }

    /**
     * Compile-time `$from_encoding` as encoding name list (string / comma-list / array).
     * Peer {@see JitMbDetectEncoding} order extraction (#35296 / #23562).
     *
     * @param list<JITVariable> $args
     * @return list<string>|null null when not fully resolvable at compile time
     */
    private static function compileTimeFromEncodingList(Context $context, array $args, int $argc): ?array
    {
        $fromIsDefault = 2 === $argc
            || (
                3 === $argc
                && (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant)
            );
        if ($fromIsDefault) {
            return [MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding()];
        }

        $arg = $args[2];
        if ($arg->compileTimeEmptyArrayLiteral ?? false) {
            return [];
        }

        $fromLit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $fromLit) {
            $parts = preg_split('/\s*,\s*/', $fromLit) ?: [];

            return array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
        }

        $arr = $arg->compileTimeArray ?? null;
        if (null !== $arr) {
            $order = [];
            foreach ($arr as $elem) {
                if (\is_string($elem)) {
                    $order[] = $elem;
                } elseif ($elem instanceof JITVariable) {
                    $s = JitStringArg::compileTimeLiteral($elem);
                    if (null === $s) {
                        return null;
                    }
                    $order[] = $s;
                } else {
                    return null;
                }
            }

            return $order;
        }

        return self::compileTimeFromEncodingNativeArray($context, $arg);
    }

    /**
     * Packed native-array encoding list (`['UTF-8','ISO-8859-1']`) via dimFetch.
     *
     * @return list<string>|null
     */
    private static function compileTimeFromEncodingNativeArray(Context $context, JITVariable $arg): ?array
    {
        if (0 === ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return null;
        }
        $n = $arg->nextFreeElement;
        if ($n <= 0) {
            return [];
        }
        $order = [];
        for ($i = 0; $i < $n; ++$i) {
            $elem = $arg->dimFetch(JITVariable::fromConstantInt($context, $i));
            $s = JitStringArg::compileTimeLiteral($elem);
            if (null === $s) {
                return null;
            }
            $order[] = $s;
        }

        return $order;
    }

    private static function isValidMbConvertEncodingName(string $name): bool
    {
        if (VmMbstring::isMbConvertPseudoEncoding($name)) {
            return true;
        }
        $canonical = MbstringEncodingRegistry::resolve($name);
        if (null !== $canonical && null !== CharsetEngine::parseEncodingSpec($canonical)) {
            return true;
        }

        return null === $canonical && null !== CharsetEngine::parseEncodingSpec($name);
    }

    private static function emitFromEncodingValueError(Context $context, string $message): Value
    {
        ExceptionBridge::ensureLinked($context);
        ExceptionBridge::emitValueErrorAndAbort($context, $message);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_encoding_from_err');

        return JitValueBox::pointer($context, JitValueBox::alloc($context));
    }

    private static function isArrayArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || (($arg->type & JITVariable::IS_NATIVE_ARRAY) !== 0)
            || ($arg->compileTimeEmptyArrayLiteral ?? false)
            || null !== ($arg->compileTimeArray ?? null);
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function foldString(Context $context, string $converted): Value
    {
        $strPtr = $context->builder->load($context->constantStringFromString($converted));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $strPtr
        );

        return $ptr;
    }

    private static function foldHashTable(Context $context, HashTable $table): Value
    {
        $htVar = HashTableHelper::variableFromVmHashTable($context, $table);
        $ht = HashTableHelper::loadHashtablePointer($context, $htVar);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }

    /**
     * @param array<string|int, mixed> $php
     */
    private static function phpAssocToHashTable(array $php): HashTable
    {
        $ht = new HashTable();
        foreach ($php as $key => $val) {
            $cell = self::phpScalarToVariable($val);
            if (\is_int($key) || (\is_string($key) && ctype_digit($key) && (string) (int) $key === $key)) {
                $ht->addIndex((int) $key, $cell);
            } else {
                $ht->add((string) $key, $cell);
            }
        }

        return $ht;
    }

    private static function phpScalarToVariable(mixed $val): VmVariable
    {
        $out = new VmVariable();
        if (null === $val) {
            $out->null();
        } elseif (\is_bool($val)) {
            $out->bool($val);
        } elseif (\is_int($val)) {
            $out->int($val);
        } elseif (\is_float($val)) {
            $out->float($val);
        } elseif (\is_string($val)) {
            $out->string($val);
        } elseif (\is_array($val)) {
            $out->array(self::phpAssocToHashTable($val));
        } else {
            $out->null();
        }

        return $out;
    }

    private static function compileTimeSourceHashTable(Context $context, JITVariable $arg): ?HashTable
    {
        if (\is_array($arg->compileTimeAssoc)) {
            return self::phpAssocToHashTable($arg->compileTimeAssoc);
        }
        if (null !== ($arg->compileTimeArray ?? null)) {
            $ht = new HashTable();
            $i = 0;
            foreach ($arg->compileTimeArray as $elem) {
                if (\is_string($elem)) {
                    $cell = new VmVariable();
                    $cell->string($elem);
                    $ht->addIndex($i, $cell);
                } elseif ($elem instanceof JITVariable) {
                    $s = JitStringArg::compileTimeLiteral($elem);
                    if (null === $s) {
                        return null;
                    }
                    $cell = new VmVariable();
                    $cell->string($s);
                    $ht->addIndex($i, $cell);
                } else {
                    return null;
                }
                ++$i;
            }

            return $ht;
        }
        if (!ArrayBuiltinHelper::isNativeArray($arg->type) || 0 === $arg->nextFreeElement) {
            if ($arg->compileTimeEmptyArrayLiteral ?? false) {
                return new HashTable();
            }

            return null;
        }
        $ht = new HashTable();
        for ($i = 0; $i < $arg->nextFreeElement; ++$i) {
            $elem = $arg->dimFetch(JITVariable::fromConstantInt($context, $i));
            $resolved = self::compileTimeSourceElement($context, $elem);
            if (null === $resolved) {
                return null;
            }
            $ht->addIndex($i, $resolved);
        }

        return $ht;
    }

    private static function compileTimeSourceElement(Context $context, JITVariable $elem): ?VmVariable
    {
        $s = JitStringArg::compileTimeLiteral($elem);
        if (null !== $s) {
            $out = new VmVariable();
            $out->string($s);

            return $out;
        }
        if (null !== $elem->compileTimeLong && JITVariable::TYPE_NATIVE_LONG === $elem->type) {
            $out = new VmVariable();
            $out->int((int) $elem->compileTimeLong);

            return $out;
        }
        if (null !== $elem->compileTimeFloat) {
            $out = new VmVariable();
            $out->float($elem->compileTimeFloat);

            return $out;
        }
        if ($elem->isNullConstant || JITVariable::TYPE_NULL === $elem->type) {
            $out = new VmVariable();
            $out->null();

            return $out;
        }
        if (null !== $elem->compileTimeBool) {
            $out = new VmVariable();
            $out->bool($elem->compileTimeBool);

            return $out;
        }

        return null;
    }
}
