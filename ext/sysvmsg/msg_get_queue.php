<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** msg_get_queue() — create or attach System V message queue (php-src ext/sysvmsg/sysvmsg.c; #3666). */
final class msg_get_queue extends Internal
{
    public function __construct()
    {
        parent::__construct('msg_get_queue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'msg_get_queue() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        MsgArgs::requireAvailable('msg_get_queue');
        $key = MsgArgs::parseKey($frame, 'msg_get_queue');
        $permissions = MsgArgs::parseOptionalInt($frame, 1, 'msg_get_queue', 'permissions');

        [$result, $message] = VmMsg::getQueue($frame->vmContext, $key, $permissions);
        if (false === $result) {
            $this->triggerWarning($frame, $message);
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            return JitMsgGet::emitArgumentCountError($context, $argc);
        }

        return JitMsgGet::invoke($context, $args);
    }

    private function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
