<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ParseUrlComponentJit;
use PHPCompiler\JIT\Builtin\ParseUrlRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for parse_url() (routing subset; mirrors VmString::parseUrl).
 */
final class JitParseUrl
{
    public static function tryResolveComponent(
        Context $context,
        JITVariable $arg,
        ?Block $block = null,
        ?Operand $operand = null
    ): ?int {
        $fromJit = ParseUrlComponentJit::compileTimeComponentInt($context, $arg);
        if (null !== $fromJit) {
            return $fromJit;
        }
        if (null !== $block && null !== $operand) {
            return self::tryResolveComponentFromBlock($context, $block, $operand);
        }

        return null;
    }

    public static function tryResolveComponentFromBlock(Context $context, Block $block, Operand $componentOp): ?int
    {
        $slot = self::operandSlot($block, $componentOp);
        if (null === $slot) {
            return null;
        }

        return self::slotParseUrlComponent($context, $block, $slot, []);
    }

    public static function parseUrl(Context $context, JITVariable $url, ?JITVariable $component = null): Value
    {
        ParseUrlRuntime::ensureLinked($context);
        if (null === $component) {
            $urlLiteral = $url->compileTimeString ?? null;
            if (null !== $urlLiteral) {
                $result = VmString::parseUrl($urlLiteral, -1);
                if (!\is_array($result)) {
                    throw new \LogicException('parse_url() compile-time URL must yield an array');
                }

                return self::materializeVmArray($context, $result);
            }

            $urlStr = JitStringBuiltinArg::lower($context, $url, 'parse_url', 0, 'url');
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__phpc_parse_url_assoc'),
                $urlStr,
                $ptr
            );

            return $ptr;
        }

        $comp = self::compileTimeLong($context, $component);
        if (null === $comp) {
            throw new \LogicException('parse_url() component must be a compile-time integer in this compiler build');
        }
        $comp = VmParseUrl::normalizeRawComponentInt($comp);
        $urlLiteral = $url->compileTimeString ?? null;
        if (null !== $urlLiteral) {
            $result = VmString::parseUrl($urlLiteral, $comp);
            if (\is_array($result)) {
                return self::materializeVmArray($context, $result);
            }

            return self::materializeVmResult($context, $result);
        }

        $urlStr = JitStringBuiltinArg::lower($context, $url, 'parse_url', 0, 'url');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (-1 === $comp) {
            $context->builder->call(
                $context->lookupFunction('__phpc_parse_url_assoc'),
                $urlStr,
                $ptr
            );

            return $ptr;
        }
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_component'),
            $urlStr,
            $i64->constInt($comp, false),
            $ptr
        );
        if (\in_array($comp, [\PHP_URL_SCHEME, \PHP_URL_HOST, \PHP_URL_USER, \PHP_URL_PASS, \PHP_URL_PATH, \PHP_URL_QUERY, \PHP_URL_FRAGMENT], true)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $ptr
            );
        }

        return $ptr;
    }

    /**
     * @param array<string, int|string> $parts
     */
    private static function materializeVmArray(Context $context, array $parts): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($parts as $key => $value) {
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            if (\is_int($value)) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $keyStr,
                    $context->getTypeFromString('int64')->constInt($value, false)
                );
                continue;
            }
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                $keyStr,
                $context->builder->load($context->constantStringFromString((string) $value))
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }

    private static function compileTimeLong(Context $context, JITVariable $var): int
    {
        $fromEnum = self::tryResolveComponent($context, $var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type) {
            throw new \LogicException('parse_url() component must be an integer in this compiler build');
        }
        if (JITVariable::KIND_VALUE !== $var->kind) {
            throw new \LogicException('parse_url() component must be a compile-time integer in this compiler build');
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        throw new \LogicException('parse_url() component must be a compile-time integer in this compiler build');
    }

    /**
     * @param string|int|null|false $result
     */
    private static function materializeVmResult(Context $context, $result): Value
    {
        if (\is_string($result)) {
            return $context->builder->load($context->constantStringFromString($result));
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $result) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        } elseif (null === $result) {
            JitValueBox::writeNull($context, $slot);
        } elseif (\is_int($result)) {
            $i64 = $context->getTypeFromString('int64');
            JitValueBox::writeLong($context, $slot, $i64->constInt($result, false));
        }

        return $ptr;
    }

    /**
     * @param array<int, true> $visited
     */
    private static function slotParseUrlComponent(Context $context, Block $block, int $slot, array $visited): ?int
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;

        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $const->type) {
                return VmParseUrl::componentFromBacking($const->toInt());
            }
            $fromEnum = VmParseUrl::tryParseUrlComponentInt($const);
            if (null !== $fromEnum) {
                return $fromEnum;
            }
        }

        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                return self::componentFromClassConstFetch($context, $block, $op);
            }
        }

        return null;
    }

    private static function componentFromClassConstFetch(Context $context, Block $block, OpCode $op): ?int
    {
        $classOp = $block->getOperand($op->arg2);
        $nameOp = $block->getOperand($op->arg3);
        if (!$classOp instanceof Literal || !$nameOp instanceof Literal) {
            return null;
        }
        if (0 !== strcasecmp(ltrim((string) $classOp->value, '\\'), 'ParseUrl')) {
            return null;
        }
        $jitObject = $context->type->object;
        if (!$jitObject instanceof \PHPCompiler\JIT\Builtin\Type\Object_) {
            return null;
        }
        $classId = $jitObject->lookup('ParseUrl');
        $backing = $jitObject->enumCaseBackingScalarForCase($classId, (string) $nameOp->value);
        if (!\is_int($backing)) {
            return null;
        }

        return VmParseUrl::componentFromBacking($backing);
    }

    private static function operandSlot(Block $block, Operand $op): ?int
    {
        foreach ($block->opCodes as $opcode) {
            foreach ([$opcode->arg1, $opcode->arg2, $opcode->arg3] as $slot) {
                if (null === $slot) {
                    continue;
                }
                try {
                    if ($block->getOperand($slot) === $op) {
                        return $slot;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
