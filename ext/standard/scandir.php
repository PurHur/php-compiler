<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** scandir() — list directory entries (VM via host PHP; JIT defers to VM). */
final class scandir extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('scandir() requires one or two arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('scandir() directory must be a string in this compiler build');
        }
        $sortingOrder = \SCANDIR_SORT_ASCENDING;
        if (2 === $argc) {
            $orderVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $orderVar->type) {
                throw new \LogicException('scandir() sorting_order must be an integer in this compiler build');
            }
            $sortingOrder = $orderVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = \scandir($pathVar->toString(), $sortingOrder);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('scandir() is not supported in JIT in this compiler build');
    }
}
