<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * gregoriantojd() — Gregorian serial day number (php-src ext/calendar/calendar.c; #7223).
 */
final class gregoriantojd extends Internal
{
    public function __construct()
    {
        parent::__construct('gregoriantojd');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gregoriantojd() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        [$month, $day, $year] = self::parseIntArgs($frame);
        $frame->returnVar->int(VmCalendar::gregorianToJd($month, $day, $year));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gregoriantojd() is not implemented for JIT in this compiler build (issue #7223)');
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function parseIntArgs(Frame $frame): array
    {
        $out = [];
        foreach ($frame->calledArgs as $i => $arg) {
            $var = $arg->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $var->type) {
                throw new \TypeError(\sprintf(
                    'gregoriantojd(): Argument #%d ($%s) must be of type int, %s given',
                    $i + 1,
                    match ($i) {
                        0 => 'month',
                        1 => 'day',
                        default => 'year',
                    },
                    self::vmTypeName($var->type)
                ));
            }
            $out[] = $var->toInt();
        }

        return [$out[0], $out[1], $out[2]];
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
