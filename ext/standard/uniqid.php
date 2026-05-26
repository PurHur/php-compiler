<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** uniqid() — time-based unique ID (VM host; JIT/AOT via gettimeofday, issue #2219). */
final class uniqid extends Internal
{
    public function __construct()
    {
        parent::__construct('uniqid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \LogicException('uniqid() accepts at most two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $prefix = '';
        $moreEntropy = false;
        if ($argc >= 1) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_STRING !== $arg->type) {
                throw new \LogicException('uniqid() prefix must be a string in this compiler build');
            }
            $prefix = $arg->toString();
        }
        if (2 === $argc) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \LogicException('uniqid() more_entropy must be boolean in this compiler build');
            }
            $moreEntropy = $arg->toBool();
        }
        $frame->returnVar->string(VmDate::uniqid($prefix, $moreEntropy));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 2) {
            throw new \LogicException('uniqid() accepts at most two arguments');
        }
        $prefixStr = $context->builder->load($context->constantStringFromString(''));
        if (isset($args[0])) {
            $prefixStr = JitStringArg::lower($context, $args[0], 'uniqid() prefix');
        }
        $moreEntropy = $context->constantFromBool(false);
        if (isset($args[1])) {
            $moreEntropy = JitBoolArg::lower($context, $args[1], 'uniqid() more_entropy');
        }

        return JitUniqid::invoke($context, $prefixStr, $moreEntropy);
    }
}
