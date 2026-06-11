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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_copy_to_string() — read stream into string (ext/standard/streams.c, #6547).
 *
 * php-src: PHP_FUNCTION(stream_copy_to_string)
 */
final class stream_copy_to_string extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_copy_to_string');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('stream_copy_to_string() requires one to three arguments in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_copy_to_string',
            1
        );
        if (null === $frame->returnVar) {
            return;
        }
        $maxlength = -1;
        $offset = 0;
        if ($argc >= 2) {
            $maxlength = self::parseLengthArg($frame->calledArgs[1]->resolveIndirect());
        }
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'stream_copy_to_string',
                3,
                'offset'
            );
        }
        $data = VmFs::streamCopyToString($handle, $maxlength, $offset);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('stream_copy_to_string() requires one to three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_copy_to_string() handle'),
            $i64
        );
        if ($argc >= 2) {
            $maxlength = JitStreamCopyToString::lowerLengthArg($context, $args[1]);
        } else {
            $maxlength = $i64->constInt(-1, true);
        }
        if (3 === $argc) {
            $offset = JitStreamCopyToString::lowerOffsetArg($context, $args[2]);
        } else {
            $offset = $i64->constInt(0, false);
        }

        return JitStreamCopyToString::invoke($context, $handle, $maxlength, $offset);
    }

    /**
     * php-src: Z_PARAM_OPTIONAL + Z_PARAM_LONG_OR_NULL for $maxlength.
     *
     * @throws \TypeError
     */
    private static function parseLengthArg(Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(self::nullableLengthTypeError(
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return -1;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::nullableLengthTypeError('array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::nullableLengthTypeError('object'));
        }
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                return (int) $var->toFloat();
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(self::nullableLengthTypeError('string'));
                }

                return (int) $s;
            default:
                throw new \TypeError(
                    self::nullableLengthTypeError(self::vmTypeName($var->type))
                );
        }
    }

    private static function nullableLengthTypeError(string $given): string
    {
        return sprintf(
            'stream_copy_to_string(): Argument #2 ($maxlength) must be of type ?int, %s given',
            $given
        );
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
