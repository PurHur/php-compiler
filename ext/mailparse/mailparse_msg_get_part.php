<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** mailparse_msg_get_part() — section handle by id (PECL mailparse; #22230). */
final class mailparse_msg_get_part extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_get_part');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_msg_get_part() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $msg = VmMailparse::requireMsgArg($frame->calledArgs[0], 'mailparse_msg_get_part', 0);
        $section = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'mailparse_msg_get_part', 1, 'mimesection');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('mailparse_msg_get_part() requires a VM context');
        }
        $part = VmMailparse::getPart($ctx, $msg, $section);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $part) {
            \trigger_error('mailparse_msg_get_part(): cannot find section '.$section.' in message', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($part);
        $frame->returnVar->copyFrom($var);
    }
}
