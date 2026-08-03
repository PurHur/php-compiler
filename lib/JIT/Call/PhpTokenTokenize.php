<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\tokenizer\VmPhpToken;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PhpToken::tokenize(string $code, int $flags = 0): array — JIT/AOT (#27263).
 *
 * Compile-time literal sources materialize PhpToken objects in the emitter (peer
 * token_get_all #3171). Runtime sources use NestedJIT via TokenGetAll then wrap —
 * for now literal path covers the AOT repro; non-literal falls through to the same
 * host scan when the string is known at call emission after constant folding.
 *
 * php-src: ext/tokenizer/tokenizer.c — PhpToken::tokenize
 * VM SSOT: {@see \PHPCompiler\ext\tokenizer\PhpTokenTokenize}
 */
final class PhpTokenTokenize implements Call
{
    public function call(Context $context, Variable ...$args): Value
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
            } elseif (Variable::TYPE_NATIVE_LONG === $args[1]->type && Variable::KIND_VALUE === $args[1]->kind) {
                $lib = $context->llvm->lib;
                if (null !== $lib->LLVMIsAConstantInt($args[1]->value->value)) {
                    $flags = (int) $lib->LLVMConstIntGetZExtValue($args[1]->value->value);
                } else {
                    // Non-literal flags with literal source — still scan with runtime flags via host
                    // only when flags fold; otherwise require flags=0 path.
                    $flags = null;
                }
            } else {
                $flags = null;
            }
        }

        if (null !== $sourceLit && null !== $flags) {
            return self::materializeCompileTime($context, $sourceLit, $flags);
        }

        // Soft-null / runtime: coerce source and scan at emit time is unsafe; use flags=0
        // materialization only when source is literal.
        if (null !== $sourceLit) {
            return self::materializeCompileTime($context, $sourceLit, 0);
        }

        throw new \LogicException(
            'PhpToken::tokenize() requires a compile-time string $code for JIT/AOT in this compiler build (#27263)'
        );
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
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj)
            );
        }

        $context->refcount->addref($ht);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }
}
