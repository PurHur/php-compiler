<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMail;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * imap_mail() — send mail via sendmail_path (php-src ext/imap/php_imap.c; #27819).
 */
final class imap_mail extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_mail');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 7) {
            throw new \ArgumentCountError(\sprintf(
                'imap_mail() expects between 3 and 7 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_mail', 0, 'to');
        $subject = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_mail', 1, 'subject');
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_mail', 2, 'message');
        if ('' === $to) {
            throw new \ValueError('imap_mail(): Argument #1 ($to) cannot be empty');
        }
        if ('' === $subject) {
            throw new \ValueError('imap_mail(): Argument #2 ($subject) cannot be empty');
        }
        if ('' === $message) {
            VmImapCore::warnMailEmptyMessage();
        }
        $headers = null;
        if ($argc >= 4) {
            $headers = self::optionalNullableString($frame->calledArgs[3], 'imap_mail', 4, 'additional_headers');
        }
        $cc = null;
        if ($argc >= 5) {
            $cc = self::optionalNullableString($frame->calledArgs[4], 'imap_mail', 5, 'cc');
        }
        $bcc = null;
        if ($argc >= 6) {
            $bcc = self::optionalNullableString($frame->calledArgs[5], 'imap_mail', 6, 'bcc');
        }
        $rpath = null;
        if ($argc >= 7) {
            $rpath = self::optionalNullableString($frame->calledArgs[6], 'imap_mail', 7, 'return_path');
        }
        $combined = VmImapCore::buildMailHeaders($headers, $cc, $bcc, $rpath);
        $frame->returnVar->bool(VmMail::send($frame, $to, $subject, $message, $combined, null));
    }

    private static function optionalNullableString(Variable $arg, string $fn, int $argNum, string $name): ?string
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($arg, $fn, $argNum - 1, $name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_mail() is not implemented for JIT in this compiler build (issue #27819)');
    }
}
