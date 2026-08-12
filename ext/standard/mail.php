<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * mail() — send mail via sendmail transport (php-src ext/standard/mail.c, #3285 / #12482).
 *
 * VM: {@see VmMail::send()} popen(sendmail_path). JIT/AOT: arg guards + false (transport follow-up).
 */
final class mail extends Internal
{
    public function __construct()
    {
        parent::__construct('mail');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'mail() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString($frame->calledArgs[0], 'mail', 'to', 0, $frame);
            InternalStrictArg::rejectNullString($frame->calledArgs[1], 'mail', 'subject', 1, $frame);
            InternalStrictArg::rejectNullString($frame->calledArgs[2], 'mail', 'message', 2, $frame);
        }
        $to = VmString::coercePathBuiltinArg($frame->calledArgs[0], 'mail', 0, 'to');
        $subject = VmString::coercePathBuiltinArg($frame->calledArgs[1], 'mail', 1, 'subject');
        $message = VmString::coercePathBuiltinArg($frame->calledArgs[2], 'mail', 2, 'message');
        $headers = null;
        if ($argc >= 4) {
            $headers = VmMail::coerceAdditionalHeaders($frame->calledArgs[3]);
        }
        $extraParams = null;
        if (5 === $argc) {
            $extraParams = VmString::coercePathBuiltinArg($frame->calledArgs[4], 'mail', 4, 'additional_params');
        }
        $ok = VmMail::send($frame, $to, $subject, $message, $headers, $extraParams);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3 || \count($args) > 5) {
            throw new \LogicException('mail() expects three to five arguments in this compiler build');
        }
        JitStringBuiltinArg::lower($context, $args[0], 'mail', 0, 'to');
        JitStringBuiltinArg::lower($context, $args[1], 'mail', 1, 'subject');
        JitStringBuiltinArg::lower($context, $args[2], 'mail', 2, 'message');
        if (isset($args[3])) {
            JitStringBuiltinArg::lower($context, $args[3], 'mail', 3, 'additional_headers');
        }
        if (isset($args[4])) {
            JitStringBuiltinArg::lower($context, $args[4], 'mail', 4, 'additional_params');
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
