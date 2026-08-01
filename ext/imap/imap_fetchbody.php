<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_fetchbody() — message body section (php-src ext/imap/php_imap.c; #3663). */
final class imap_fetchbody extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_fetchbody');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'imap_fetchbody() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_fetchbody');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_fetchbody', 2, 'message_num');
        $section = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_fetchbody', 2, 'section');
        $flags = 0;
        if (4 === $argc) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_fetchbody', 4, 'flags');
        }
        $body = VmImapCore::fetchBody($connection, $msgNo, $section, $flags);
        if (false === $body) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($body);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_fetchbody() is not implemented for JIT in this compiler build (issue #3663)');
    }
}
