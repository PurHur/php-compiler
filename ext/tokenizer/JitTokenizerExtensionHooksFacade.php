<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\PhpTokenRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TokenizerExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * tokenizer surfaces for lib/JIT Call PhpToken* (#36204).
 *
 * php-src: ext/tokenizer/tokenizer.c — PhpToken::tokenize / __construct / getTokenName.
 * Registered from {@see Module::jitInit} so Call files do not import ext/tokenizer.
 */
final class JitTokenizerExtensionHooksFacade implements TokenizerExtensionHooks
{
    public function tokenize(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError('PhpToken::tokenize() expects at least 1 argument, 0 given');
        }

        $sourceLit = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        $flags = 0;
        if ($argc >= 2) {
            if (null !== $args[1]->compileTimeLong) {
                $flags = (int) $args[1]->compileTimeLong;
            } elseif (JITVariable::TYPE_NATIVE_LONG === $args[1]->type && JITVariable::KIND_VALUE === $args[1]->kind) {
                $lib = $context->llvm->lib;
                if (null !== $lib->LLVMIsAConstantInt($args[1]->value->value)) {
                    $flags = (int) $lib->LLVMConstIntGetZExtValue($args[1]->value->value);
                } else {
                    $flags = null;
                }
            } else {
                $flags = null;
            }
        }

        if (null !== $sourceLit && null !== $flags) {
            return self::materializeCompileTime($context, $sourceLit, $flags);
        }

        if (null !== $sourceLit) {
            return self::materializeCompileTime($context, $sourceLit, 0);
        }

        throw new \LogicException(
            'PhpToken::tokenize() requires a compile-time string $code for JIT/AOT in this compiler build (#27263)'
        );
    }

    public function construct(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \ArgumentCountError(
                'PhpToken::__construct() expects at least 2 arguments, '.\max(0, \count($args) - 1).' given'
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $context->type->object->lookup('PhpToken');

        $id = JitLongArg::lower($context, $args[1], 'PhpToken::__construct id');
        $idVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $id
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'PhpToken', VmPhpToken::PROP_ID),
            $idVar,
            JITVariable::TYPE_NATIVE_LONG
        );

        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'PhpToken',
            VmPhpToken::PROP_TEXT,
            $args[2]
        );

        $line = \count($args) >= 4
            ? JitLongArg::lower($context, $args[3], 'PhpToken::__construct line')
            : $context->constantFromInteger(-1);
        $lineVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $line
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'PhpToken', VmPhpToken::PROP_LINE),
            $lineVar,
            JITVariable::TYPE_NATIVE_LONG
        );

        $pos = \count($args) >= 5
            ? JitLongArg::lower($context, $args[4], 'PhpToken::__construct pos')
            : $context->constantFromInteger(-1);
        $posVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $pos
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'PhpToken', VmPhpToken::PROP_POS),
            $posVar,
            JITVariable::TYPE_NATIVE_LONG
        );

        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    public function getTokenName(Context $context, JITVariable ...$args): Value
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

    private static function materializeCompileTime(Context $context, string $source, int $flags): Value
    {
        $parts = VmPhpToken::tokenizeParts($source, $flags);
        $classId = $context->type->object->lookup('PhpToken');
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $need = $sizeT->constInt(\max(1, \count($parts)), false);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);

        foreach ($parts as $i => $part) {
            $obj = $context->type->object->allocate($classId);
            ReflectionSetup::markConstructed($context, $obj);
            ReflectionSetup::emitSetIntegerProperty(
                $context,
                $obj,
                'PhpToken',
                VmPhpToken::PROP_ID,
                $part['id']
            );
            $i8p = $context->getTypeFromString('int8*');
            $sizeTLen = $context->getTypeFromString('size_t');
            $cstr = $context->builder->pointerCast(
                $context->constantFromString($part['text']),
                $i8p
            );
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $obj,
                'PhpToken',
                VmPhpToken::PROP_TEXT,
                $cstr,
                $sizeTLen->constInt(\strlen($part['text']), false)
            );
            ReflectionSetup::emitSetIntegerProperty(
                $context,
                $obj,
                'PhpToken',
                VmPhpToken::PROP_LINE,
                $part['line']
            );
            ReflectionSetup::emitSetIntegerProperty(
                $context,
                $obj,
                'PhpToken',
                VmPhpToken::PROP_POS,
                $part['pos']
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $sizeT->constInt($i, false),
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            );
        }

        $context->refcount->addref($ht);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
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
