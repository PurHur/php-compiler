<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/** VM helpers for variadic parameters and call introspection (issue #197). */
final class Variadic
{
    /**
     * Pack trailing call arguments into a list array for a variadic parameter slot.
     *
     * @param list<Variable> $calledArgs
     */
    public static function assignPacked(Variable $dest, array $calledArgs, int $startIdx): void
    {
        $dest->newArray();
        $ht = $dest->toArray();
        for ($i = $startIdx, $n = count($calledArgs); $i < $n; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($calledArgs[$i]);
            $ht->append($copy);
        }
    }

    /**
     * Arguments visible to func_get_args() / func_num_args() in the current user frame.
     *
     * @return list<Variable>
     */
    public static function visibleCallArgs(Frame $frame): array
    {
        $user = self::userFrame($frame);
        $offset = null !== $user->block->func->class ? 1 : 0;

        return array_slice($user->calledArgs, $offset);
    }

    private static function userFrame(Frame $frame): Frame
    {
        for ($f = $frame->parent; null !== $f; $f = $f->parent) {
            if (null !== $f->block && null !== $f->block->func && null === $f->handler) {
                return $f;
            }
        }

        throw new \LogicException('Must be called from a user function');
    }
}
