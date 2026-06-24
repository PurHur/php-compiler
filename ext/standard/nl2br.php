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
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * nl2br() for strings (subset of PHP; JIT/AOT via __string__nl2br LLVM lowering).
 */
final class nl2br extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('nl2br() requires one or two arguments');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'nl2br', 'string', 0, $frame);
        $subject = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'nl2br',
            0,
            'string'
        );
        $useXhtml = true;
        if (2 === $argc) {
            $useXhtml = self::resolveUseXhtmlBool($frame, 1);
        }
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::nl2br($subject, $useXhtml))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('nl2br() requires one or two arguments');
        }

        JitInternalStrictArg::rejectNullString($context, $args[0], 'nl2br', 'string', 1);

        $strLit = JitStringArg::compileTimeLiteral($args[0]);
        $flagLit = 2 === $argc ? JitStringArg::compileTimeLiteral($args[1]) : null;
        if (null !== $strLit && (1 === $argc || null !== $flagLit)) {
            $useXhtml = true;
            if (null !== $flagLit) {
                $useXhtml = self::coerceUseXhtmlStringLiteral($flagLit);
            }

            return $context->builder->load(
                $context->constantStringFromString(VmString::nl2br($strLit, $useXhtml))
            );
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'nl2br', 0, 'string');
        $i8 = $context->getTypeFromString('int8');
        $useXhtmlI8 = $i8->constInt(1, false);
        if (2 === $argc) {
            $useXhtmlI8 = $context->builder->zExt(
                $this->jitBool($context, $args[1], 'nl2br(): Argument #2 ($use_xhtml)'),
                $i8
            );
        }

        return JitNl2br::nl2br($context, $str, $useXhtmlI8);
    }

    private static function resolveUseXhtmlBool(Frame $frame, int $argIndex): bool
    {
        $flag = $frame->calledArgs[$argIndex]->resolveIndirect();

        return self::coerceUseXhtmlOperand($flag, $argIndex);
    }

    /**
     * php-src Z_PARAM_BOOL coercion for nl2br() use_xhtml (#5056).
     */
    private static function coerceUseXhtmlOperand(Variable $flag, int $argIndex): bool
    {
        $label = sprintf('nl2br(): Argument #%d ($use_xhtml)', $argIndex + 1);
        switch ($flag->type) {
            case Variable::TYPE_BOOLEAN:
                return $flag->toBool();
            case Variable::TYPE_INTEGER:
                return 0 !== $flag->toInt();
            case Variable::TYPE_FLOAT:
                return 0.0 !== $flag->toFloat();
            case Variable::TYPE_STRING:
                return self::coerceUseXhtmlStringLiteral($flag->toString());
            case Variable::TYPE_ARRAY:
                throw new \TypeError($label.' must be of type bool, array given');
            case Variable::TYPE_OBJECT:
                throw new \TypeError($label.' must be of type bool, '.self::vmTypeName($flag->type).' given');
            case Variable::TYPE_NULL:
                throw new \TypeError($label.' must be of type bool, null given');
            default:
                throw new \TypeError($label.' must be of type bool, '.self::vmTypeName($flag->type).' given');
        }
    }

    private static function coerceUseXhtmlStringLiteral(string $literal): bool
    {
        $lower = strtolower($literal);
        if (\in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (\in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
            return false;
        }

        throw new \TypeError('nl2br(): Argument #2 ($use_xhtml) must be of type bool, string given');
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }

}
