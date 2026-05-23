<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mkdir() — VM via VmFs; JIT/AOT via __compiler_mkdir (libc mkdir(2), recursive in C). */
final class mkdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('mkdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('mkdir() requires one to three arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('mkdir() directory must be a string in this compiler build');
        }
        $mode = 0777;
        if ($argc >= 2) {
            $modeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \LogicException('mkdir() mode must be an integer in this compiler build');
            }
            $mode = $modeVar->toInt();
        }
        $recursive = false;
        if (3 === $argc) {
            $recVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $recVar->type) {
                throw new \LogicException('mkdir() recursive flag must be a boolean in this compiler build');
            }
            $recursive = $recVar->toBool();
        }
        $frame->returnVar->bool(VmFs::mkdir($pathVar->toString(), $mode, $recursive));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('mkdir() requires one to three arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('mkdir() directory must be a string in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $mode = $i64->constInt(0777, false);
        if ($argc >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('mkdir() mode must be an integer in this compiler build');
            }
            $mode = $context->builder->truncOrBitCast(
                $context->helper->loadValue($args[1]),
                $i64
            );
        }
        $recursive = $i1->constInt(0, false);
        if ($argc >= 3) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[2]->type) {
                throw new \LogicException('mkdir() recursive flag must be a boolean in this compiler build');
            }
            $recursive = $context->helper->loadValue($args[2]);
        }

        return JitMkdir::invoke(
            $context,
            $context->helper->loadValue($args[0]),
            $mode,
            $recursive
        );
    }
}
