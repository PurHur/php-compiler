<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mktime() — local Unix timestamp from parts (VM VmDate; JIT StringMktime, #3292). */
final class mktime extends Internal
{
    public function __construct()
    {
        parent::__construct('mktime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('mktime() expects at least 1 argument, 0 given');
        }
        if ($argc > 6) {
            throw new \ArgumentCountError('mktime() expects at most 6 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hour = self::parseRequiredIntArg($frame, $frame->calledArgs[0], 1);
        $minute = $argc >= 2 ? self::parseNullableIntArg($frame->calledArgs[1], 2) : null;
        $second = $argc >= 3 ? self::parseNullableIntArg($frame->calledArgs[2], 3) : null;
        $month = $argc >= 4 ? self::parseNullableIntArg($frame->calledArgs[3], 4) : null;
        $day = $argc >= 5 ? self::parseNullableIntArg($frame->calledArgs[4], 5) : null;
        $year = $argc >= 6 ? self::parseNullableIntArg($frame->calledArgs[5], 6) : null;

        $result = VmDate::mktime($hour, $minute, $second, $month, $day, $year);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 6) {
            throw new \LogicException('mktime() expects between one and six arguments in this compiler build');
        }

        return JitMktime::invoke(
            $context,
            $args[0],
            $args[1] ?? null,
            $args[2] ?? null,
            $args[3] ?? null,
            $args[4] ?? null,
            $args[5] ?? null,
            $argc
        );
    }

    private static function parseRequiredIntArg(Frame $frame, Variable $var, int $position): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            // Z_PARAM_LONG $hour — soft-null DEP+coerce on 8.4 (ext/date/php_date.c; #21491, reverts #20227).
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(self::intTypeError($position, $var->type));
            }
            VmNullNumberParamDeprecation::emit($frame, 'mktime', $position, self::argName($position), 'int');

            return 0;
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(self::intTypeError($position, $var->type));
        }

        return $var->toInt();
    }

    private static function parseIntArg(Variable $var, int $position): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(self::intTypeError($position, $var->type));
        }

        return $var->toInt();
    }

    private static function parseNullableIntArg(Variable $var, int $position): ?int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        throw new \TypeError(self::nullableIntTypeError($position, $var->type));
    }

    private static function intTypeError(int $position, int $type): string
    {
        return \sprintf(
            'mktime(): Argument #%d ($%s) must be of type int, %s given',
            $position,
            self::argName($position),
            self::vmTypeName($type)
        );
    }

    private static function nullableIntTypeError(int $position, int $type): string
    {
        return \sprintf(
            'mktime(): Argument #%d ($%s) must be of type ?int, %s given',
            $position,
            self::argName($position),
            self::vmTypeName($type)
        );
    }

    private static function argName(int $position): string
    {
        return match ($position) {
            1 => 'hour',
            2 => 'minute',
            3 => 'second',
            4 => 'month',
            5 => 'day',
            6 => 'year',
            default => 'arg',
        };
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
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}
