<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_context_create() — VM returns array stream-context representation (#1377).
 *
 * JIT: {@see JitStreamContextCreate} (string-key options arrays; #1377, #2457).
 */
final class stream_context_create extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \LogicException(
                'stream_context_create() accepts at most two arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $optionsVar = $argc >= 1 ? $frame->calledArgs[0] : null;
        $paramsVar = 2 === $argc ? $frame->calledArgs[1] : null;

        $frame->returnVar->array(VmStreamContext::createFromVmOptions($optionsVar, $paramsVar));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamContextCreate::invoke($context, ...$args);
    }
}
