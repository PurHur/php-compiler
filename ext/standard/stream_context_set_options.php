<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_context_set_options() — batch merge stream wrapper options (PHP 8.3+; ext/standard/streams.c).
 *
 * Wrong argc → ArgumentCountError (#28680; peer #28682).
 */
final class stream_context_set_options extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_set_options');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/streamsfuncs.c — ArgumentCountError (#28680).
        $this->requireExactArgCount($frame, 'stream_context_set_options', 2);
        $ok = VmStreamContext::setOptions($frame->calledArgs[0], $frame->calledArgs[1]);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — peer #28682 / #28680.
        if (!$this->requireExactJitArgCount($context, $args, 'stream_context_set_options', 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        return JitStreamContextSetOptions::invoke($context, ...$args);
    }
}
