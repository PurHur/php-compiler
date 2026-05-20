<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
/**
 * VM helpers for extract() / compact() (caller scope via Frame::parent).
 */
final class VmScope
{
    /** PHP EXTR_SKIP — do not overwrite variables that already hold a value. */
    public const EXTR_SKIP = 6;

    public static function requireCaller(Frame $frame): Frame
    {
        if (null === $frame->parent || null === $frame->parent->block) {
            throw new \LogicException('extract() and compact() require an active caller frame');
        }

        return $frame->parent;
    }

    public static function slotForName(Frame $caller, string $name): ?int
    {
        return $caller->block->slotIndexForVariableName($name);
    }

    public static function extract(Frame $frame): int
    {
        if (\count($frame->calledArgs) < 1 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('extract() requires one or two arguments in this compiler build');
        }
        $caller = self::requireCaller($frame);
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }
        $flags = self::EXTR_SKIP;
        if (2 === \count($frame->calledArgs)) {
            $flagsArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsArg->type) {
                throw new \LogicException('extract() flags must be an integer in this compiler build');
            }
            $flags = $flagsArg->toInt();
        }

        return self::extractIntoCaller($caller, $array->toArray(), $flags);
    }

    private static function extractIntoCaller(Frame $caller, HashTable $table, int $flags): int
    {
        $imported = 0;
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $name = $keyVar->toString();
            $slot = self::slotForName($caller, $name);
            if (null === $slot) {
                continue;
            }
            $target = $caller->scope[$slot];
            if (self::EXTR_SKIP === ($flags & self::EXTR_SKIP) && self::callerVarIsSet($target)) {
                continue;
            }
            $target->copyFrom($valueVar);
            ++$imported;
        }

        return $imported;
    }

    public static function compact(Frame $frame): HashTable
    {
        $caller = self::requireCaller($frame);
        $result = new HashTable();
        foreach ($frame->calledArgs as $arg) {
            $nameVar = $arg->resolveIndirect();
            if (Variable::TYPE_STRING !== $nameVar->type) {
                throw new \LogicException('compact() arguments must be string variable names in this compiler build');
            }
            $name = $nameVar->toString();
            $slot = self::slotForName($caller, $name);
            if (null === $slot) {
                continue;
            }
            $value = $caller->scope[$slot];
            $copy = new Variable();
            $copy->copyFrom($value);
            $result->add($name, $copy);
        }

        return $result;
    }

    private static function callerVarIsSet(Variable $var): bool
    {
        $v = $var->resolveIndirect();
        if ($v->isUndefined()) {
            return false;
        }

        return Variable::TYPE_NULL !== $v->type;
    }

}
