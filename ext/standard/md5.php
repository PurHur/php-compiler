<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** md5() — hex digest via native __compiler_hash (issue #179 follow-up). */
final class md5 extends Internal
{
    public function __construct()
    {
        parent::__construct('md5');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('md5() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $data->type) {
            throw new \LogicException('md5() only supports strings in this compiler build');
        }
        $raw = false;
        if (2 === $argc) {
            $rawArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('md5() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $result = VmHash::hash('md5', $data->toString(), $raw);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('md5() requires one or two arguments in this compiler build');
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[1])) {
            $raw = JitBoolArg::lower($context, $args[1], 'md5() raw_output');
        }

        return JitMd5::digest($context, $this->jitString($context, $args[0], 'md5() argument #1'), $raw);
    }
}
