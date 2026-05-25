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

/** microtime() — sub-second clock (VM host; JIT/AOT via gettimeofday, issue #2186). */
final class microtime extends Internal
{
    public function __construct()
    {
        parent::__construct('microtime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('microtime() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $asFloat = false;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \LogicException('microtime() as_float must be boolean in this compiler build');
            }
            $asFloat = $arg->toBool();
        }
        if ($asFloat) {
            $frame->returnVar->float(VmDate::microtime(true));

            return;
        }
        $frame->returnVar->string(VmDate::microtime(false));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('microtime() accepts at most one argument');
        }
        $asFloat = $context->constantFromBool(false);
        if (isset($args[0])) {
            $asFloat = JitBoolArg::lower($context, $args[0], 'microtime() as_float');
        }

        return JitDate::microtime($context, $asFloat);
    }
}
