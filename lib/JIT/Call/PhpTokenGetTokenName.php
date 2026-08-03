<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\tokenizer\TokenConstants;
use PHPCompiler\ext\tokenizer\VmPhpToken;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\PhpTokenRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * PhpToken::getTokenName(): ?string — JIT/AOT (#27263).
 *
 * Inline id→name select-walk (peer GetClassRuntime #26854).
 *
 * php-src: ext/tokenizer/tokenizer.c — PhpToken::getTokenName
 * VM SSOT: {@see \PHPCompiler\ext\tokenizer\PhpTokenGetTokenName}
 */
final class PhpTokenGetTokenName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('PhpToken::getTokenName() requires $this');
        }
        PhpTokenRuntime::ensureLinked($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $id = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'PhpToken',
            VmPhpToken::PROP_ID
        );

        $named = self::emitNameSelectWalk($context, $id);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->pointerCast($named, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $nameLen = $context->builder->load($lenPtr);

        $namedBb = BasicBlockHelper::append($context, 'phptoken_gtn_named');
        $fallbackBb = BasicBlockHelper::append($context, 'phptoken_gtn_fallback');
        $doneBb = BasicBlockHelper::append($context, 'phptoken_gtn_done');
        $hasName = $context->builder->icmp(Builder::INT_NE, $nameLen, $i64->constInt(0, false));
        $context->builder->branchIf($hasName, $namedBb, $fallbackBb);

        $retSlot = JitValueBox::alloc($context);
        $retPtr = JitValueBox::pointer($context, $retSlot);

        $context->builder->positionAtEnd($namedBb);
        $context->builder->call($context->lookupFunction('__value__writeString'), $retPtr, $named);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fallbackBb);
        [$textCstr, $textLen] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'PhpToken',
            VmPhpToken::PROP_TEXT
        );
        $isSingle = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->zExt($textLen, $i64),
            $i64->constInt(1, false)
        );
        $fbSingleBb = BasicBlockHelper::append($context, 'phptoken_gtn_single');
        $fbNullBb = BasicBlockHelper::append($context, 'phptoken_gtn_null');
        $context->builder->branchIf($isSingle, $fbSingleBb, $fbNullBb);

        $context->builder->positionAtEnd($fbSingleBb);
        $textStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($textLen, $i64),
            $textCstr
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $retPtr, $textStr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fbNullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $retPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $retSlot;
    }

    /** @return Value {@see __string__*} */
    private static function emitNameSelectWalk(Context $context, Value $tokenId): Value
    {
        $result = $context->builder->load($context->constantStringFromString(''));
        foreach (TokenConstants::registeredConstants() as $name => $id) {
            if ('TOKEN_PARSE' === $name) {
                continue;
            }
            $expected = $context->constantFromInteger((int) $id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $tokenId, $expected);
            $candidate = $context->builder->load($context->constantStringFromString((string) $name));
            $result = $context->builder->select($isId, $candidate, $result);
        }

        return $result;
    }
}
