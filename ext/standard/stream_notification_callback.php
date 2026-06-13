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
 * stream_notification_callback() — register global stream progress notifier (ext/standard/streams.c, #6055).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_notification_callback)
 */
final class stream_notification_callback extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_notification_callback');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_notification_callback() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL !== $arg->type) {
            VmStreamNotification::requireValidCallback($frame->calledArgs[0]);
        }
        $previous = VmStreamNotification::setGlobal($frame->calledArgs[0]);
        $frame->returnVar->copyFrom($previous);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamNotificationCallback::invoke($context, ...$args);
    }
}
