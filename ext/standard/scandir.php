<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** scandir() — list directory entries (VM via VmDir; JIT via StringFsGlobVecJit, #7405). */
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
        $result = VmDir::scandir($pathVar->toString(), $sortingOrder);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->array(VmFs::stringListToArray($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('scandir() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('scandir() directory must be a string in this compiler build');
        }
        $i32 = $context->getTypeFromString('int32');
        $sort = $i32->constInt(0, false);
        if (2 === $argc) {
            if (JITVariable::TYPE_INTEGER !== $args[1]->type
                && JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('scandir() sorting_order must be an integer in this compiler build');
            }
            $sort = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'scandir() sorting_order'),
                $i32
            );
        }

        $path = $this->jitString($context, $args[0], 'scandir() argument #1');
        StringFsGlob::ensureLinked($context);

        return JitFsGlob::scandir($context, $path, $sort);
    }
}
