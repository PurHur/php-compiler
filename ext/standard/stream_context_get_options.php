<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_context_get_options() — read merged stream wrapper options (ext/standard/streams.c).
 *
 * Excess argc → Zend ArgumentCountError (#30785; php-src streamsfuncs.c).
 */
final class stream_context_get_options extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_get_options');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/streamsfuncs.c — ArgumentCountError (#30785).
        $this->requireExactArgCount($frame, 'stream_context_get_options', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmStreamContext::getOptionsHashTable($frame->calledArgs[0]));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'stream_context_get_options', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitStreamContextGetOptions::invoke($context, ...$args);
    }
}
