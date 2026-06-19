<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * htmlspecialchars() for strings (subset of PHP; JIT supports flags for ENT_QUOTES / ENT_COMPAT).
 */
final class htmlspecialchars extends Internal
{
    private const DEFAULT_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('htmlspecialchars() requires one to four arguments');
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'htmlspecialchars',
            0,
            'string'
        );
        $flags = self::DEFAULT_FLAGS;
        $encoding = 'UTF-8';
        $doubleEncode = true;
        if ($argc >= 2) {
            $flagsVar = $frame->calledArgs[1]->resolveIndirect();
            if (VMVariable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('htmlspecialchars() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if ($argc >= 3) {
            $encoding = self::resolveEncodingVm($frame->calledArgs[2]->resolveIndirect());
        }
        if (4 === $argc) {
            $deVar = $frame->calledArgs[3]->resolveIndirect();
            if (VMVariable::TYPE_BOOLEAN !== $deVar->type) {
                throw new \LogicException('htmlspecialchars() double_encode must be a boolean in this compiler build');
            }
            $doubleEncode = $deVar->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::htmlspecialchars($string, $flags, $encoding, $doubleEncode));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('htmlspecialchars() requires one to four arguments');
        }

        $folded = self::tryCompileTimeHtmlspecialchars($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $effectiveArgc = self::jitEffectiveArgc($argc, $args);
        if ($effectiveArgc >= 3) {
            throw new \LogicException(
                'htmlspecialchars() JIT only supports string and optional flags in this compiler build'
            );
        }

        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $maybeLiteral = $args[0]->compileTimeString ?? null;
            // Stack locals (KIND_VARIABLE) may be reassigned in catch before merge (#2387).
            if (null !== $maybeLiteral && JITVariable::KIND_VALUE === $args[0]->kind) {
                $literal = $maybeLiteral;
            }
        }
        if (null !== $literal && 1 === $effectiveArgc) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::htmlspecialchars($literal, self::DEFAULT_FLAGS, 'UTF-8', true)
                )
            );
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'htmlspecialchars', 0, 'string');
        $flags = $context->getTypeFromString('int64')->constInt(self::DEFAULT_FLAGS, false);
        if ($effectiveArgc >= 2) {
            $flags = JitLongArg::lower($context, $args[1], 'htmlspecialchars() flags');
        }

        return JitHtmlspecialchars::escape($context, $str, $flags);
    }

    private static function resolveEncodingVm(VMVariable $encVar): string
    {
        if (VMVariable::TYPE_NULL === $encVar->type) {
            return 'UTF-8';
        }
        if (VMVariable::TYPE_STRING !== $encVar->type) {
            throw new \TypeError(
                'htmlspecialchars(): Argument #3 ($encoding) must be of type ?string, '
                .self::vmTypeName($encVar->type).' given'
            );
        }

        return $encVar->toString();
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function jitEffectiveArgc(int $argc, array $args): int
    {
        if ($argc >= 3 && self::encodingArgIsNull($args[2])) {
            return 2;
        }

        return $argc;
    }

    private static function encodingArgIsNull(JITVariable $var): bool
    {
        return JITVariable::TYPE_NULL === $var->type || $var->isNullConstant;
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            VMVariable::TYPE_INTEGER => 'int',
            VMVariable::TYPE_FLOAT => 'float',
            VMVariable::TYPE_BOOLEAN => 'bool',
            VMVariable::TYPE_ARRAY => 'array',
            VMVariable::TYPE_OBJECT => 'object',
            VMVariable::TYPE_RESOURCE => 'resource',
            default => 'unknown type',
        };
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeHtmlspecialchars(Context $context, array $args): ?Value
    {
        $argc = count($args);
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $literal || JITVariable::KIND_VALUE !== $args[0]->kind) {
            return null;
        }

        $flags = self::DEFAULT_FLAGS;
        if ($argc >= 2) {
            $flagsVal = self::compileTimeLong($context, $args[1]);
            if (null === $flagsVal) {
                return null;
            }
            $flags = $flagsVal;
        }

        $encoding = 'UTF-8';
        if ($argc >= 3 && !self::encodingArgIsNull($args[2])) {
            $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
            if (null === $encodingLit || JITVariable::KIND_VALUE !== $args[2]->kind) {
                return null;
            }
            $encoding = $encodingLit;
        }

        $doubleEncode = true;
        if ($argc >= 4) {
            $doubleEncodeVal = self::compileTimeBool($context, $args[3]);
            if (null === $doubleEncodeVal) {
                return null;
            }
            $doubleEncode = $doubleEncodeVal;
        }

        return $context->builder->load(
            $context->constantStringFromString(
                VmString::htmlspecialchars($literal, $flags, $encoding, $doubleEncode)
            )
        );
    }

    private static function compileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }
        if (null !== $var->compileTimeConstantName && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($var->compileTimeConstantName);
            if (null !== $phpVar && VMVariable::TYPE_INTEGER === $phpVar->resolveIndirect()->type) {
                return $phpVar->resolveIndirect()->toInt();
            }
        }

        return null;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
            }
        }
        if (null !== $var->compileTimeConstantName && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($var->compileTimeConstantName);
            if (null !== $phpVar && VMVariable::TYPE_BOOLEAN === $phpVar->resolveIndirect()->type) {
                return $phpVar->resolveIndirect()->toBool();
            }
        }

        return null;
    }

}
