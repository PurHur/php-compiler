<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** msg_receive() — receive message from System V queue (php-src ext/sysvmsg/sysvmsg.c; #3666). */
final class msg_receive extends Internal
{
    public function __construct()
    {
        parent::__construct('msg_receive');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 8) {
            throw new \ArgumentCountError(
                $argc < 5
                    ? 'msg_receive() expects at least 5 arguments, '.$argc.' given'
                    : 'msg_receive() expects at most 8 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        MsgArgs::requireAvailable('msg_receive');
        $object = MsgArgs::parseQueue($frame, 'msg_receive');
        $host = MsgArgs::requireHost($object, 'msg_receive');
        $desiredType = MsgArgs::parseRequiredInt($frame, 1, 'msg_receive', 'desired_message_type');
        $maxSize = MsgArgs::parseRequiredInt($frame, 3, 'msg_receive', 'max_message_size');
        $unserialize = MsgArgs::parseOptionalBool($frame, 5, 'msg_receive', 'unserialize') ?? true;
        $flags = MsgArgs::parseOptionalInt($frame, 6, 'msg_receive', 'flags') ?? 0;

        [$ok, $receivedType, $message, $errorCode, $warn] = VmMsg::receive(
            $host,
            $desiredType,
            $maxSize,
            $unserialize,
            $flags
        );
        if ($ok) {
            $typeOut = new Variable(Variable::TYPE_INTEGER);
            $typeOut->int((int) $receivedType);
            $frame->calledArgs[2]->byRefTarget()->copyFrom($typeOut);
            $frame->calledArgs[4]->byRefTarget()->copyFrom(VmJson::import($message));
        } else {
            if ('' !== $warn) {
                $this->triggerWarning($frame, $warn);
            }
            if (isset($frame->calledArgs[7]) && null !== $errorCode) {
                $errOut = new Variable(Variable::TYPE_INTEGER);
                $errOut->int($errorCode);
                $frame->calledArgs[7]->byRefTarget()->copyFrom($errOut);
            }
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 5 || $argc > 8) {
            return JitMsgReceive::emitArgumentCountError($context, $argc);
        }

        return JitMsgReceive::invoke($context, $args);
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
