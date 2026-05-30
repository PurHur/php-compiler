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

/**
 * gettimeofday() — wall-clock array or float (ext/standard/microtimers.c parity, #3208).
 */
final class gettimeofday extends Internal
{
    public function __construct()
    {
        parent::__construct('gettimeofday');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('gettimeofday() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $asFloat = false;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \LogicException('gettimeofday() get_as_float must be boolean in this compiler build');
            }
            $asFloat = $arg->toBool();
        }
        if ($asFloat) {
            $frame->returnVar->float(VmDate::gettimeofdayFloat());

            return;
        }
        $frame->returnVar->array(VmDate::gettimeofdayArray());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('gettimeofday() accepts at most one argument');
        }
        $asFloat = $context->constantFromBool(false);
        if (isset($args[0])) {
            $asFloat = JitBoolArg::lower($context, $args[0], 'gettimeofday() get_as_float');
        }

        return JitGettimeofday::call($context, $asFloat);
    }
}
