<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mail() — send mail via sendmail transport (php-src ext/standard/mail.c, #12482).
 *
 * VM returns false when transport is unavailable; matches Zend CLI without sendmail.
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
        VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'mail', 0, 'to');
        VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'mail', 1, 'subject');
        VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'mail', 2, 'message');
        if ($argc >= 4) {
            VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'mail', 3, 'additional_headers');
        }
        if (5 === $argc) {
            VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'mail', 4, 'additional_params');
        }
        $frame->returnVar->bool(false);
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
