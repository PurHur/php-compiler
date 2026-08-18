<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\Web\Params;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering for web_int(), web_string(), web_bool() (issue #157).
 */
final class JitWebParams
{
    public static function webInt(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('web_int() requires three to five arguments in this compiler build');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            || JITVariable::TYPE_STRING !== $args[1]->type
            || JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException(
                'web_int() requires (array, string key, int default) in this compiler build'
            );
        }
        if ($argc >= 4 && JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
            throw new \LogicException('web_int() min must be an integer in this compiler build');
        }
        if ($argc >= 5 && JITVariable::TYPE_NATIVE_LONG !== $args[4]->type) {
            throw new \LogicException('web_int() max must be an integer in this compiler build');
        }

        $i64 = JitStringIndex::i64($context);
        $defaultVal = $context->helper->loadValue($args[2]);

        $missingBlock = BasicBlockHelper::append($context, 'web_int_missing');
        $invalidBlock = BasicBlockHelper::append($context, 'web_int_invalid');
        $numBlock = BasicBlockHelper::append($context, 'web_int_num');
        $convertBlock = BasicBlockHelper::append($context, 'web_int_convert');
        $doneBlock = BasicBlockHelper::append($context, 'web_int_done');

        $exists = (new array_key_exists())->call($context, $args[1], $args[0]);
        $context->builder->branchIf($exists, $convertBlock, $missingBlock);

        $context->builder->positionAtEnd($missingBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($convertBlock);
        $ht = $context->helper->loadValue($args[0]);
        $key = $context->helper->loadValue($args[1]);
        $boxed = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $key
        );
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxed
        );
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $str);
        $isNum = (new is_numeric())->call($context, $strVar);
        $context->builder->branchIf($isNum, $numBlock, $invalidBlock);

        $context->builder->positionAtEnd($invalidBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($numBlock);
        $parsed = self::stringToInt64($context, $str);
        $result = $parsed;
        if ($argc >= 4) {
            $min = $context->helper->loadValue($args[3]);
            $result = JitStringIndex::max($context, $result, $min);
        }
        if ($argc >= 5) {
            $max = $context->helper->loadValue($args[4]);
            $result = JitStringIndex::min($context, $result, $max);
        }
        $valueBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($defaultVal, $missingBlock);
        $phi->addIncoming($defaultVal, $invalidBlock);
        $phi->addIncoming($result, $valueBlock);

        return $phi;
    }

    public static function webString(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('web_string() requires two to four arguments in this compiler build');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException(
                'web_string() requires (array, string key) in this compiler build'
            );
        }
        $empty = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            JitStringIndex::zero($context)
        );
        if (2 === $argc) {
            $defaultStr = $empty;
        } elseif (JITVariable::TYPE_STRING !== $args[2]->type) {
            throw new \LogicException('web_string() default must be a string in this compiler build');
        } else {
            $defaultStr = $context->helper->loadValue($args[2]);
        }
        if ($argc >= 4 && JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
            throw new \LogicException('web_string() maxLen must be an integer in this compiler build');
        }

        $missingBlock = BasicBlockHelper::append($context, 'web_str_missing');
        $trimBlock = BasicBlockHelper::append($context, 'web_str_trim');
        $doneBlock = BasicBlockHelper::append($context, 'web_str_done');

        $exists = (new array_key_exists())->call($context, $args[1], $args[0]);
        $context->builder->branchIf($exists, $trimBlock, $missingBlock);

        $context->builder->positionAtEnd($missingBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($trimBlock);
        $ht = $context->helper->loadValue($args[0]);
        $key = $context->helper->loadValue($args[1]);
        $boxed = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $key
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxed
        );
        $rawVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $raw);
        $trimmed = (new string_trim())->call($context, $rawVar);
        $final = $trimmed;
        if ($argc >= 4) {
            $lenArg = $context->helper->loadValue($args[3]);
            $zero = JitStringIndex::zero($context);
            $trimVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $trimmed);
            $offsetVar = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $zero
            );
            $lenVar = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $lenArg
            );
            $final = (new substr())->call($context, $trimVar, $offsetVar, $lenVar);
        }
        $valueBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($defaultStr->typeOf());
        $phi->addIncoming($defaultStr, $missingBlock);
        $phi->addIncoming($final, $valueBlock);

        return $phi;
    }

    public static function webBool(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('web_bool() requires two or three arguments in this compiler build');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException(
                'web_bool() requires (array, string key) in this compiler build'
            );
        }
        $defaultVal = $context->constantFromBool(false);
        if (3 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[2]->type) {
                throw new \LogicException('web_bool() default must be a boolean in this compiler build');
            }
            $defaultVal = $context->helper->loadValue($args[2]);
        }

        $exists = (new array_key_exists())->call($context, $args[1], $args[0]);
        $valueResult = self::coerceStringToBoolJit(
            $context,
            self::webString($context, $args[0], $args[1]),
            $defaultVal
        );

        $picked = $context->builder->select($exists, $valueResult, $defaultVal);

        return $context->builder->zExt($picked, JitStringIndex::i64($context));
    }

    private static function coerceStringToBoolJit(Context $context, Value $str, Value $defaultVal): Value
    {
        $rawVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $str);
        $isTrue = self::stringMatchesAnyLiteral($context, $rawVar, Params::BOOL_TRUE_STRINGS);
        $isFalse = self::stringMatchesAnyLiteral($context, $rawVar, Params::BOOL_FALSE_STRINGS);
        $trueVal = $context->constantFromBool(true);
        $falseVal = $context->constantFromBool(false);
        $knownFalse = $context->builder->select($isFalse, $falseVal, $defaultVal);

        return $context->builder->select($isTrue, $trueVal, $knownFalse);
    }

    /**
     * @param list<string> $literals
     */
    private static function stringMatchesAnyLiteral(Context $context, JITVariable $str, array $literals): Value
    {
        $match = null;
        foreach ($literals as $literal) {
            $litVar = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($literal))
            );
            $cmp = (new strcmp())->call($context, $str, $litVar);
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $cmp->typeOf()->constInt(0, false)
            );
            $match = null === $match ? $eq : $context->builder->or($match, $eq);
        }

        return $match ?? $context->constantFromBool(false);
    }

    private static function stringToInt64(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'web_int_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        // strtod(3) via LibcExtern::ensureStrtodDecl after always-on drop (#31997).
        LibcExtern::ensureStrtodDecl($context);
        $dbl = $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
        $i64 = JitStringIndex::i64($context);

        return $context->builder->fpToSi($dbl, $i64);
    }
}
