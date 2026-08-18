<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ParseUrlComponentJit;
use PHPCompiler\JIT\Builtin\ParseUrlRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
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
        // Soft-null — coerce+deprecate on forward profile (#21188); fold '' → ['path'=>''].
        if (JITVariable::TYPE_NULL === $url->type || ($url->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                return JitStringBuiltinArg::lowerStrictOrCoercible($context, $url, 'parse_url', 0, 'url');
            }
            // Side-effect: E_DEPRECATED + empty string (result folded below).
            JitStringBuiltinArg::lowerTrimFamilyString($context, $url, 'parse_url', 0, 'url');
        }
        if (null === $component) {
            $urlLiteral = $url->compileTimeString ?? JitStringArg::compileTimeLiteral($url);
            if (
                null === $urlLiteral
                && (JITVariable::TYPE_NULL === $url->type || ($url->isNullConstant ?? false))
            ) {
                $urlLiteral = '';
            }
            if (null !== $urlLiteral) {
                $result = VmString::parseUrl($urlLiteral, -1);
                // Invalid port / empty non-file host → false (php-src url.c); do not throw (#22822, #32085).
                if (false === $result) {
                    return self::materializeVmResult($context, false);
                }
                if (!\is_array($result)) {
                    throw new \LogicException('parse_url() compile-time URL must yield an array');
                }

                return self::materializeVmArray($context, $result);
            }

            $urlStr = self::jitUrlArg($context, $url);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__phpc_parse_url_assoc'),
                $urlStr,
                $ptr
            );

            return $ptr;
        }

        $compConst = self::tryCompileTimeComponentInt($context, $component);
        if (null !== $compConst) {
            $comp = VmParseUrl::normalizeRawComponentInt($compConst);
            $urlLiteral = $url->compileTimeString ?? JitStringArg::compileTimeLiteral($url);
            if (
                null === $urlLiteral
                && (JITVariable::TYPE_NULL === $url->type || ($url->isNullConstant ?? false))
            ) {
                $urlLiteral = '';
            }
            if (null !== $urlLiteral) {
                $result = VmString::parseUrl($urlLiteral, $comp);
                if (\is_array($result)) {
                    return self::materializeVmArray($context, $result);
                }

                return self::materializeVmResult($context, $result);
            }

            $urlStr = self::jitUrlArg($context, $url);
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

        // Runtime component — Z_PARAM_LONG soft-null DEP+0 via JitIntdiv (#24942).
        $compVal = JitIntdiv::lowerIntBuiltinArgForCaller(
            $context,
            $component,
            'parse_url',
            2,
            'component'
        );
        $urlStr = self::jitUrlArg($context, $url);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_component'),
            $urlStr,
            $compVal,
            $ptr
        );

        return $ptr;
    }

    /** Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/url.c). */
    private static function jitUrlArg(Context $context, JITVariable $url): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $url,
                'parse_url',
                0,
                'url'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $url,
            'parse_url',
            0,
            'url'
        );
    }

    /**
     * @param array<string, int|string> $parts
     */
    private static function materializeVmArray(Context $context, array $parts): Value
    {
        // Same INIT_ARRAY / addElement path as `["k"=>"v"]` literals (#27078).
        $slot = JitValueBox::alloc($context);
        $arrayVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            $slot
        );
        $arrayVar->valueBoxHashtable = true;
        HashTableHelper::initArray($context, $arrayVar);
        foreach ($parts as $key => $value) {
            $keyVar = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString((string) $key))
            );
            $keyVar->compileTimeString = (string) $key;
            if (\is_int($value)) {
                $elem = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt($value, false)
                );
            } else {
                $elem = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString((string) $value))
                );
                $elem->compileTimeString = (string) $value;
            }
            HashTableHelper::addElement($context, $arrayVar, $elem, $keyVar);
        }

        return JitValueBox::pointer($context, $slot);
    }

    /** @return ?int null when component must be lowered at runtime */
    private static function tryCompileTimeComponentInt(Context $context, JITVariable $var): ?int
    {
        $fromEnum = self::tryResolveComponent($context, $var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        // Soft-null → DEP + 0 (PHP_URL_SCHEME); AOT skips DEP IR mid-fold (#24942, #21593).
        if (JITVariable::TYPE_NULL === $var->type || ($var->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                \PHPCompiler\JIT\InternalStrictArg::requireInt(
                    $context,
                    $var,
                    'parse_url',
                    'component',
                    2
                );

                return null;
            }
            if (!$context->isUserScriptAot()) {
                JitIntdiv::emitNullIntDeprecation($context, 'parse_url', 2, 'component');
            }

            return 0;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type) {
            return null;
        }
        if (JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
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
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
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
            // Leave TYPE_NULL unresolved so parseUrl()/compileTimeLong emits soft-null DEP (#24942).
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
