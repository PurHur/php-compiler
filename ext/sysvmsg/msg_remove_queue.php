<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** msg_remove_queue() — destroy System V message queue (php-src ext/sysvmsg/sysvmsg.c; #3666). */
final class msg_remove_queue extends Internal
{
    public function __construct()
    {
        parent::__construct('msg_remove_queue');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'msg_remove_queue() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        MsgArgs::requireAvailable('msg_remove_queue');
        $object = MsgArgs::parseQueue($frame, 'msg_remove_queue');
        $host = MsgArgs::requireHost($object, 'msg_remove_queue');

        $result = VmMsg::remove($host);
        if ($result) {
            VmMsg::detachObject($object);
        } else {
            $this->triggerWarning($frame, 'msg_remove_queue() failed');
        }
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitMsgRemove::emitArgumentCountError($context, $argc);
        }

        return JitMsgRemove::invoke($context, $args[0]);
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
