<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrWordCount as StrWordCountRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for str_word_count() (format 0 LLVM; formats 1/2 via C runtime — issue #3584).
 */
final class JitStrWordCount
{
    private static int $blockSerial = 0;

    public static function count(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $data = $context->builder->structGep($str, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $id = (string) (++self::$blockSerial);
        $posSlot = $context->builder->alloca($i64, 1, 'str_word_count_pos_'.$id);
        $countSlot = $context->builder->alloca($i64, 1, 'str_word_count_n_'.$id);
        $inWordSlot = $context->builder->alloca($i8, 1, 'str_word_count_in_'.$id);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $countSlot);
        $context->builder->store($i8->constInt(0, false), $inWordSlot);

        $head = BasicBlockHelper::append($context, 'str_word_count_head_'.$id);
        $body = BasicBlockHelper::append($context, 'str_word_count_body_'.$id);
        $done = BasicBlockHelper::append($context, 'str_word_count_done_'.$id);

        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $pos, $len);
        $context->builder->branchIf($pastEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $chPtr = $context->builder->inBoundsGEP($data, $pos);
        $ch = $context->builder->load($chPtr);
        $chI64 = $context->builder->zExt($ch, $i64);
        $inWord = $context->builder->load($inWordSlot);
        $inWordI64 = $context->builder->zExt($inWord, $i64);

        $isLetter = self::isLetter($context, $chI64);
        $isApostrophe = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(39, false));
        $isHyphen = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(45, false));
        $inWordBool = $context->builder->icmp(Builder::INT_NE, $inWordI64, $zero);
        $innerPunct = $context->builder->or(
            $context->builder->and($inWordBool, $isApostrophe),
            $context->builder->and($inWordBool, $isHyphen)
        );
        $isWordChar = $context->builder->or($isLetter, $innerPunct);

        $wasInWord = $inWordBool;
        $context->builder->store(
            $context->builder->zExt($isWordChar, $i8),
            $inWordSlot
        );

        $startWord = $context->builder->and(
            $isWordChar,
            $context->builder->not($wasInWord)
        );
        $count = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap(
                $count,
                $context->builder->zExt($startWord, $i64)
            ),
            $countSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $one),
            $posSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function isLetter(Context $context, Value $ord): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(90, false))
        );
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(122, false))
        );

        return $context->builder->or($isUpper, $isLower);
    }

    /**
     * Build a compile-time __hashtable__ from VM word list / offset map (formats 1 and 2).
     */
    public static function hashTableFromVmResult(Context $context, array $result, int $format): Value
    {
        $ht = new HashTable();
        if (1 === $format) {
            foreach ($result as $word) {
                $value = new Variable();
                $value->string($word);
                $ht->append($value);
            }
        } else {
            foreach ($result as $pos => $word) {
                $value = new Variable();
                $value->string($word);
                $ht->addIndex((int) $pos, $value);
            }
        }
        $jit = HashTableHelper::variableFromVmHashTable($context, $ht);

        return $jit->value;
    }

    /**
     * Runtime lowering for format 1/2 (and optional $chars) via phpc_str_word_count.c.
     */
    public static function wordHashTableRuntime(
        Context $context,
        Value $str,
        Value $format,
        ?Value $chars
    ): Value {
        StrWordCountRuntime::ensureLinked($context);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $charsArg = $chars ?? $empty;

        return $context->builder->call(
            $context->lookupFunction('__compiler_str_word_count_words'),
            $str,
            $format,
            $charsArg
        );
    }

    public static function compileTimeFormat(JITVariable $arg): int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
            || JITVariable::KIND_VALUE !== $arg->kind) {
            throw new \LogicException('str_word_count() format must be a compile-time integer in this compiler build');
        }
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }

        throw new \LogicException('str_word_count() format must be a compile-time integer in this compiler build');
    }

    public static function jitFormatArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        return JitLongArg::lower($context, $arg, 'str_word_count() argument #2 ($format)');
    }
}
