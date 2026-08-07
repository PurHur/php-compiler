<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** msg_send() — send message to System V queue (php-src ext/sysvmsg/sysvmsg.c; #3666). */
final class msg_send extends Internal
{
    public function __construct()
    {
        parent::__construct('msg_send');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 6) {
            throw new \ArgumentCountError(
                $argc < 3
                    ? 'msg_send() expects at least 3 arguments, '.$argc.' given'
                    : 'msg_send() expects at most 6 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        MsgArgs::requireAvailable('msg_send');
        $object = MsgArgs::parseQueue($frame, 'msg_send');
        $host = MsgArgs::requireHost($object, 'msg_send');
        $messageType = MsgArgs::parseRequiredInt($frame, 1, 'msg_send', 'message_type');
        $message = VmHttpBuildQuery::export($frame->calledArgs[2], $frame);
        $serialize = MsgArgs::parseOptionalBool($frame, 3, 'msg_send', 'serialize') ?? true;
        $blocking = MsgArgs::parseOptionalBool($frame, 4, 'msg_send', 'blocking') ?? true;

        [$ok, $errorCode, $warn] = VmMsg::send($host, $messageType, $message, $serialize, $blocking);
        if (!$ok) {
            if ('' !== $warn) {
                $this->triggerWarning($frame, $warn);
            }
            if (isset($frame->calledArgs[5]) && null !== $errorCode) {
                $out = new Variable(Variable::TYPE_INTEGER);
                $out->int($errorCode);
                $frame->calledArgs[5]->byRefTarget()->copyFrom($out);
            }
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 6) {
            return JitMsgSend::emitArgumentCountError($context, $argc);
        }

        return JitMsgSend::invoke($context, $args);
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
