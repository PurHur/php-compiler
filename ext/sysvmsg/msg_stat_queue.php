<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** msg_stat_queue() — message queue status array (php-src ext/sysvmsg/sysvmsg.c; #3666). */
final class msg_stat_queue extends Internal
{
    public function __construct()
    {
        parent::__construct('msg_stat_queue');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'msg_stat_queue() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        MsgArgs::requireAvailable('msg_stat_queue');
        $object = MsgArgs::parseQueue($frame, 'msg_stat_queue');
        $host = MsgArgs::requireHost($object, 'msg_stat_queue');

        $stat = VmMsg::stat($host);
        if (false === $stat) {
            $this->triggerWarning($frame, 'msg_stat_queue() failed');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->array(VmMsg::statToHashTable($stat));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'msg_stat_queue() is not supported for JIT/AOT in this compiler build (issue #3666)'
        );
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
