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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * number_format() for integers and floats (C-style locale subset; LLVM JIT/AOT).
 *
 * php-src: ext/standard/number_format.c — Z_PARAM_LONG / Z_PARAM_STR
 */
final class number_format extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $numVar = $frame->calledArgs[0]->resolveIndirect();
        $num = VmNumberFormat::coerceFloat($numVar);
        $decimals = 0;
        if ($argc >= 2) {
            $decVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $decVar->type) {
                throw new \TypeError(\sprintf(
                    'number_format(): Argument #2 ($num_decimal_places) must be of type int, %s given',
                    self::vmTypeName($decVar)
                ));
            }
            $decimals = $decVar->toInt();
        }
        $decimalSeparator = '.';
        if ($argc >= 3) {
            $sepVar = $frame->calledArgs[2]->resolveIndirect();
            $decimalSeparator = self::requireSeparatorString($sepVar, 2, 'dec_separator');
        }
        $thousandsSeparator = ',';
        if (4 === $argc) {
            $thouVar = $frame->calledArgs[3]->resolveIndirect();
            $thousandsSeparator = self::requireSeparatorString($thouVar, 3, 'thousands_separator');
        }
        $frame->returnVar->string(VmNumberFormat::format(
            $num,
            $decimals,
            $decimalSeparator,
            $thousandsSeparator
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }

        return JitNumberFormat::format($context, ...$args);
    }

    private static function requireSeparatorString(Variable $var, int $argIndex, string $paramName): string
    {
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(\sprintf(
                'number_format(): Argument #%d ($%s) must be of type string, %s given',
                $argIndex + 1,
                $paramName,
                self::vmTypeName($var)
            ));
        }

        return $var->toString();
    }

    private static function vmTypeName(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

        return match ($var->type) {
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
