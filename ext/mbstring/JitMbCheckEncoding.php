<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbCheckEncodingRuntime;
use PHPCompiler\JIT\Builtin\StringUtf8Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_check_encoding() (#4571, #35211 runtime encoding, #35365 array).
 *
 * Compile-time fold + UTF-8 literal via StringUtf8Runtime; runtime encoding via NestedJIT
 * {@see MbCheckEncodingJitHelper} (peer {@see JitMbStrlen} / #34625).
 * Array `$value`: compile-time string lists fold; assigned TYPE_VALUE arrays dispatch at
 * runtime (string vs hashtable) — {@see HashTableWriteLlvm} stores plain strings in
 * {@see JITVariable::$compileTimeArray} (#35365 leftover of #35211).
 */
final class JitMbCheckEncoding
{
    private static int $seq = 0;

    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        $var = self::compileTimeVar($args);
        if (!\array_key_exists('var', $var) && 0 === \count($args)) {
            return $context->constantFromBool(true);
        }
        if (!\array_key_exists('var', $var)) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding && isset($args[1])) {
            return null;
        }
        // Unknown / unsupported encoding → NestedJIT (catchable ValueError) (#35211).
        if (null !== $encoding && null === self::canonicalCheckEncoding($encoding)) {
            return null;
        }

        return $context->constantFromBool(
            VmMbstring::checkEncoding($var['var'], $encoding)
        );
    }

    /**
     * @param JITVariable[] $args
     */
    public static function lowerRuntime(Context $context, array $args): Value
    {
        if (0 === \count($args)) {
            return $context->constantFromBool(true);
        }

        $argc = \count($args);
        $encodingLit = null;
        if ($argc >= 2
            && JITVariable::TYPE_NULL !== $args[1]->type
            && !($args[1]->isNullConstant ?? false)
        ) {
            $encodingLit = JitStringArg::compileTimeLiteral($args[1]);
        }

        // Compile-time known string list (inline / CTA) — fold path missed when encoding runtime.
        if (isset($args[0]) && self::isKnownCompileTimeArray($args[0])) {
            $list = self::compileTimeStringList($args[0]);
            if (null !== $list) {
                return self::lowerRuntimeStringList($context, $list, $args, $argc, $encodingLit);
            }
        }

        // Assigned locals are TYPE_VALUE (#35365) — must not JitStringBuiltinArg::lower a hashtable.
        if (JITVariable::TYPE_VALUE === $args[0]->type || JitValueBox::isValueOperand($args[0])) {
            return self::lowerRuntimeValueBox($context, $args, $argc, $encodingLit);
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type
            || (($args[0]->type & JITVariable::IS_NATIVE_ARRAY) !== 0)
        ) {
            return self::lowerRuntimeHashtableArg($context, $args, $argc, $encodingLit);
        }

        return self::lowerRuntimeStringArg($context, $args, $argc, $encodingLit);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function lowerRuntimeStringArg(
        Context $context,
        array $args,
        int $argc,
        ?string $encodingLit
    ): Value {
        // Fast path: omitted / null / known UTF-8|ASCII|8BIT literal (#4571).
        if (null === $encodingLit && ($argc < 2
            || JITVariable::TYPE_NULL === $args[1]->type
            || ($args[1]->isNullConstant ?? false))
        ) {
            return self::utf8ValidFromArg($context, $args[0]);
        }
        if (null !== $encodingLit) {
            $canonical = self::canonicalCheckEncoding($encodingLit);
            if ('ASCII' === $canonical || '8BIT' === $canonical) {
                return $context->constantFromBool(true);
            }
            if ('UTF-8' === $canonical) {
                return self::utf8ValidFromArg($context, $args[0]);
            }
        }

        $enc = self::encodingValue($context, $args, $argc, $encodingLit);
        $str = JitStringBuiltinArg::lower($context, $args[0], 'mb_check_encoding', 0, 'var');

        return self::callCheckHelperBool($context, $str, $enc);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function lowerRuntimeValueBox(
        Context $context,
        array $args,
        int $argc,
        ?string $encodingLit
    ): Value {
        $enc = self::encodingValue($context, $args, $argc, $encodingLit);
        MbCheckEncodingRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_check_encoding_value');

        $valPtr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $tag = 'mce_vb'.(++self::$seq);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $strBlock = BasicBlockHelper::append($context, $tag.'_str');
        $arrBlock = BasicBlockHelper::append($context, $tag.'_arr');
        $nullBlock = BasicBlockHelper::append($context, $tag.'_null');
        $longBlock = BasicBlockHelper::append($context, $tag.'_long');
        $falseBlock = BasicBlockHelper::append($context, $tag.'_false');
        $merge = BasicBlockHelper::append($context, $tag.'_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_STRING & 0x7f, false)
        );
        $afterStr = BasicBlockHelper::append($context, $tag.'_after_str');
        $context->builder->branchIf($isString, $strBlock, $afterStr);

        $context->builder->positionAtEnd($afterStr);
        // VM TYPE_ARRAY=6 and JIT TYPE_HASHTABLE=7 both appear in boxed values (#35365).
        $isArrayVm = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_ARRAY & 0x7f, false)
        );
        $isArrayJit = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isArray = $context->builder->or($isArrayVm, $isArrayJit);
        $afterArr = BasicBlockHelper::append($context, $tag.'_after_arr');
        $context->builder->branchIf($isArray, $arrBlock, $afterArr);

        $context->builder->positionAtEnd($afterArr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_NULL & 0x7f, false)
        );
        $afterNull = BasicBlockHelper::append($context, $tag.'_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($afterNull);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_INTEGER & 0x7f, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($strBlock);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $context->builder->store(self::callCheckHelperBool($context, $str, $enc), $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($arrBlock);
        $ht = $context->builder->call($context->lookupFunction('__value__readHashtable'), $valPtr);
        $context->builder->store(self::checkHashtable($context, $ht, $enc), $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($longBlock);
        // (string) int is always valid in UTF-8/ASCII/8BIT — still probe encoding via NestedJIT.
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store(self::callCheckHelperBool($context, $empty, $enc), $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->store($i1->constInt(0, false), $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function lowerRuntimeHashtableArg(
        Context $context,
        array $args,
        int $argc,
        ?string $encodingLit
    ): Value {
        $enc = self::encodingValue($context, $args, $argc, $encodingLit);
        MbCheckEncodingRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_check_encoding_ht');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $args[0]);

        return self::checkHashtable($context, $ht, $enc);
    }

    private static function checkHashtable(Context $context, Value $ht, Value $enc): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $accSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(1, false), $accSlot);
        self::walkPackedCheck($context, $ht, $enc, $accSlot);
        self::walkStringKeysCheck($context, $ht, $enc, $accSlot);

        return $context->builder->load($accSlot);
    }

    private static function walkPackedCheck(Context $context, Value $ht, Value $enc, Value $accSlot): void
    {
        $tag = 'mce_pk'.(++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, $tag.'_head');
        $body = BasicBlockHelper::append($context, $tag.'_body');
        $work = BasicBlockHelper::append($context, $tag.'_work');
        $next = BasicBlockHelper::append($context, $tag.'_next');
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $work, $next);

        $context->builder->positionAtEnd($work);
        $entry = HashTableHelper::listEntryPointer($context, $ht, $idx);
        self::andCheckValuePtr($context, $entry, $enc, $accSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function walkStringKeysCheck(Context $context, Value $ht, Value $enc, Value $accSlot): void
    {
        $tag = 'mce_sk'.(++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);
        $head = BasicBlockHelper::append($context, $tag.'_head');
        $body = BasicBlockHelper::append($context, $tag.'_body');
        $next = BasicBlockHelper::append($context, $tag.'_next');
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        self::andCheckValuePtr($context, $valField, $enc, $accSlot);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function andCheckValuePtr(Context $context, Value $valuePtr, Value $enc, Value $accSlot): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $tag = 'mce_el'.(++self::$seq);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $context->structFieldMap['__value__']['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $strBlock = BasicBlockHelper::append($context, $tag.'_str');
        $longBlock = BasicBlockHelper::append($context, $tag.'_long');
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $done = BasicBlockHelper::append($context, $tag.'_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_STRING & 0x7f, false)
        );
        $afterStr = BasicBlockHelper::append($context, $tag.'_after_str');
        $context->builder->branchIf($isString, $strBlock, $afterStr);

        $context->builder->positionAtEnd($afterStr);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_INTEGER & 0x7f, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $failBlock);

        $context->builder->positionAtEnd($strBlock);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $ok = self::callCheckHelperBool($context, $str, $enc);
        $prev = $context->builder->load($accSlot);
        $context->builder->store($context->builder->and($prev, $ok), $accSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        // (string) int always valid in leaf encodings — NestedJIT '' asserts $encoding (#35365).
        $empty = $context->builder->load($context->constantStringFromString(''));
        $okLong = self::callCheckHelperBool($context, $empty, $enc);
        $prevLong = $context->builder->load($accSlot);
        $context->builder->store($context->builder->and($prevLong, $okLong), $accSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->store($i1->constInt(0, false), $accSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Known compile-time string list + omitted / literal / runtime encoding (#35365).
     *
     * @param list<string> $list
     * @param JITVariable[] $args
     */
    private static function lowerRuntimeStringList(
        Context $context,
        array $list,
        array $args,
        int $argc,
        ?string $encodingLit
    ): Value {
        if ([] === $list) {
            if (null === $encodingLit && $argc >= 2
                && JITVariable::TYPE_NULL !== $args[1]->type
                && !($args[1]->isNullConstant ?? false)
            ) {
                return self::andCheckHelpers($context, [''], $args, $encodingLit);
            }

            return $context->constantFromBool(true);
        }

        if (null === $encodingLit && ($argc < 2
            || JITVariable::TYPE_NULL === $args[1]->type
            || ($args[1]->isNullConstant ?? false))
        ) {
            return self::andUtf8ValidLiterals($context, $list);
        }
        if (null !== $encodingLit) {
            $canonical = self::canonicalCheckEncoding($encodingLit);
            if ('ASCII' === $canonical || '8BIT' === $canonical) {
                return $context->constantFromBool(true);
            }
            if ('UTF-8' === $canonical) {
                return self::andUtf8ValidLiterals($context, $list);
            }
        }

        return self::andCheckHelpers($context, $list, $args, $encodingLit);
    }

    /**
     * @param list<string> $list
     */
    private static function andUtf8ValidLiterals(Context $context, array $list): Value
    {
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(1, false);
        foreach ($list as $i => $s) {
            $str = $context->builder->load($context->constantStringFromString($s));
            $valid = StringUtf8Runtime::validFromPtr($context, $str);
            $ok = $context->builder->icmp(Builder::INT_NE, $valid, $zero);
            $acc = 0 === $i ? $ok : $context->builder->and($acc, $ok);
        }

        return $acc;
    }

    /**
     * @param list<string> $list
     * @param JITVariable[] $args
     */
    private static function andCheckHelpers(
        Context $context,
        array $list,
        array $args,
        ?string $encodingLit
    ): Value {
        $enc = self::encodingValue($context, $args, \count($args), $encodingLit);
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(1, false);
        foreach ($list as $i => $s) {
            $str = $context->builder->load($context->constantStringFromString($s));
            $ok = self::callCheckHelperBool($context, $str, $enc);
            $acc = 0 === $i ? $ok : $context->builder->and($acc, $ok);
        }

        return $acc;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function encodingValue(
        Context $context,
        array $args,
        int $argc,
        ?string $encodingLit
    ): Value {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbCheckEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_check_encoding_enc');

        if (null !== $encodingLit) {
            return $context->builder->load($context->constantStringFromString($encodingLit));
        }
        if ($argc < 2
            || JITVariable::TYPE_NULL === $args[1]->type
            || ($args[1]->isNullConstant ?? false)
        ) {
            return $context->builder->load($context->constantStringFromString('UTF-8'));
        }

        return JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'mb_check_encoding',
            1,
            'encoding'
        );
    }

    private static function callCheckHelperBool(Context $context, Value $str, Value $enc): Value
    {
        MbCheckEncodingRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_check_encoding_call');
        $ok = $context->builder->call(
            MbCheckEncodingRuntime::checkHelper($context),
            $str,
            $enc
        );
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $ok, $zero);
    }

    private static function utf8ValidFromArg(Context $context, JITVariable $arg): Value
    {
        $str = JitStringBuiltinArg::lower($context, $arg, 'mb_check_encoding', 0, 'var');
        $valid = StringUtf8Runtime::validFromPtr($context, $str);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $valid, $zero);
    }

    private static function canonicalCheckEncoding(string $encoding): ?string
    {
        $upper = \strtoupper($encoding);
        if ('UTF-8' === $upper || 'UTF8' === $upper) {
            return 'UTF-8';
        }
        if ('ASCII' === $upper || 'US-ASCII' === $upper) {
            return 'ASCII';
        }
        if ('8BIT' === $upper || 'BINARY' === $upper) {
            return '8BIT';
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     *
     * @return array{var?: array<string>|string|null}
     */
    private static function compileTimeVar(array $args): array
    {
        if (!isset($args[0])) {
            return [];
        }
        if (JITVariable::TYPE_NULL === $args[0]->type) {
            return ['var' => ''];
        }
        if (JITVariable::TYPE_STRING === $args[0]->type && null !== ($args[0]->compileTimeString ?? null)) {
            return ['var' => $args[0]->compileTimeString];
        }
        $list = self::compileTimeStringList($args[0]);
        if (null !== $list) {
            return ['var' => $list];
        }

        return [];
    }

    /**
     * Packed compile-time string list from `compileTimeArray` / empty literal (#35365).
     *
     * @return list<string>|null
     */
    private static function compileTimeStringList(JITVariable $arg): ?array
    {
        if ($arg->compileTimeEmptyArrayLiteral ?? false) {
            return [];
        }
        $arr = $arg->compileTimeArray ?? null;
        if (!\is_array($arr)) {
            return null;
        }
        $items = [];
        foreach ($arr as $elem) {
            if (\is_string($elem)) {
                $items[] = $elem;
            } elseif ($elem instanceof JITVariable) {
                $s = JitStringArg::compileTimeLiteral($elem) ?? $elem->compileTimeString ?? null;
                if (null === $s) {
                    return null;
                }
                $items[] = $s;
            } else {
                return null;
            }
        }

        return $items;
    }

    private static function isKnownCompileTimeArray(JITVariable $arg): bool
    {
        return ($arg->compileTimeEmptyArrayLiteral ?? false)
            || \is_array($arg->compileTimeArray ?? null);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return JitStringArg::compileTimeLiteral($args[$index])
            ?? $args[$index]->compileTimeString
            ?? null;
    }
}
