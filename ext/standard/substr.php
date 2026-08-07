<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * substr() for strings with integer offset and optional length (subset of PHP).
 *
 * php-src `ext/standard/string.c` `php_substr` clamps oversize positive lengths silently —
 * there is no Z_STR_TRUNCATED / "String is truncated" E_WARNING (#28556; re-#22489).
 * Forward-profile `$truncate` remains accepted for now (phantom arity — #27749) but must not
 * enable a clip warning Zend never emits.
 */
final class substr extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        $supportsTruncate = CompilerVersion::supportsSubstrTruncate();
        $maxArgs = $supportsTruncate ? 4 : 3;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'substr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > $maxArgs) {
            throw new \ArgumentCountError(\sprintf(
                'substr() expects at most %d arguments, %d given',
                $maxArgs,
                $argc
            ));
        }
        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#24817; reverts #24694/#18980 TypeError).
        // TypeError for null→string is PHP 9.0 (RFC deprecate_null_to_scalar_internal_arg), not 8.4.
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'substr', 0, 'string');
        $offset = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'substr', 2, 'offset');
        // Accept phantom 4th $truncate on forward profile (#27749) but never warn on clip (#28556).
        if ($supportsTruncate && 4 === $argc) {
            VmMath::parseBoolBuiltinArgForFrame($frame, 3, 'substr', 4, 'truncate');
        }
        if (3 === $argc) {
            $length = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL === $length->type) {
                $frame->returnVar->string(VmString::substr($string, $offsetInt, null, false, $frame));

                return;
            }
            $lengthInt = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'substr', 3, 'length');
            $frame->returnVar->string(VmString::substr($string, $offsetInt, $lengthInt, false, $frame));

            return;
        }
        $frame->returnVar->string(VmString::substr($string, $offsetInt, null, false, $frame));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        $supportsTruncate = CompilerVersion::supportsSubstrTruncate();
        $maxArgs = $supportsTruncate ? 4 : 3;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'substr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > $maxArgs) {
            throw new \ArgumentCountError(\sprintf(
                'substr() expects at most %d arguments, %d given',
                $maxArgs,
                $argc
            ));
        }
        // Phantom 4th $truncate accepted on forward profile (#27749); never emit clip warning (#28556).
        if ($supportsTruncate && 4 === $argc) {
            self::compileTimeBool($context, $args[3]);
        }

        $strLit = $args[0]->compileTimeString ?? null;
        if (null === $strLit
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            // Soft-null fold outside strict_types (#24817 / #21189); strict keeps TypeError path.
            if ($context->callerStrictTypes) {
                $strLit = null;
            } else {
                $strLit = '';
            }
        }
        if (null !== $strLit) {
            $offsetLit = self::compileTimeSignedLong($context, $args[1]);
            if (null !== $offsetLit) {
                $folded = null;
                if (3 === $argc) {
                    // php-src basic_functions.stub.php — ?int $length = null means "to end" (#25749)
                    if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
                        $folded = VmString::substr($strLit, $offsetLit, null, false);
                    } else {
                        $lengthLit = self::compileTimeSignedLong($context, $args[2]);
                        if (null !== $lengthLit) {
                            $folded = VmString::substr($strLit, $offsetLit, $lengthLit, false);
                        }
                    }
                } else {
                    $folded = VmString::substr($strLit, $offsetLit, null, false);
                }
                if (null !== $folded) {
                    if ('' === $strLit && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
                        JitStringBuiltinArg::emitNullStringParamDeprecation(
                            $context,
                            'substr',
                            0,
                            'string'
                        );
                    }

                    return $context->builder->load($context->constantStringFromString($folded));
                }
            }
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#24817; peer strpos #21189).
        if ($context->callerStrictTypes) {
            $str = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'substr', 0, 'string');
        } else {
            $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'substr', 0, 'string');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'substr_str_cont');
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $charPtr = $context->builder->structGep($str, $map['value']);
        $zero = JitStringIndex::zero($context);
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'substr', 2, 'offset');
        $start = JitStringIndex::clamp($context, $offset, $zero, $len);

        $sliceLen = null;
        if (3 === $argc) {
            // ?int $length = null → "to end" (same Z_PARAM_LONG_OR_NULL shape as array_splice; #25749)
            [$hasLength, $lengthArg] = JitIntdiv::lowerSpliceLengthArg(
                $context,
                $args[2],
                'substr',
                3,
                'length'
            );
            $toEnd = $context->builder->sub($len, $start);
            $toEnd = JitStringIndex::max($context, $toEnd, $zero);
            $negLen = $context->builder->icmp(Builder::INT_SLT, $lengthArg, $zero);
            $remaining = $context->builder->sub($len, $start);
            $adjustedLen = $context->builder->select(
                $negLen,
                $context->builder->add($remaining, $lengthArg),
                $lengthArg
            );
            $bounded = JitStringIndex::min(
                $context,
                JitStringIndex::max($context, $adjustedLen, $zero),
                $remaining
            );
            $sliceLen = $context->builder->select($hasLength, $bounded, $toEnd);
            // No Z_STR_TRUNCATED path — php-src clamps silently (#28556).
        } else {
            $sliceLen = $context->builder->sub($len, $start);
            $sliceLen = JitStringIndex::max($context, $sliceLen, $zero);
        }

        return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen);
    }

    private static function compileTimeBool(Context $context, JITVariable $arg): ?bool
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return 0 !== (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
            }
        }

        return null;
    }

    private static function compileTimeSignedLong(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }

        return null;
    }

}
