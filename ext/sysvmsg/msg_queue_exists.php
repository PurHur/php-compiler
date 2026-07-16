<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** msg_queue_exists() — whether a System V message queue key exists (php-src ext/sysvmsg/sysvmsg.c; #3666). */
final class msg_queue_exists extends Internal
{
    public function __construct()
    {
        parent::__construct('msg_queue_exists');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'msg_queue_exists() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        MsgArgs::requireAvailable('msg_queue_exists');
        $key = MsgArgs::parseKey($frame, 'msg_queue_exists');
        $frame->returnVar->bool(VmMsg::queueExists($key));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'msg_queue_exists() is not supported for JIT/AOT in this compiler build (issue #3666)'
        );
    }
}
