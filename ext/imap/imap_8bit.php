<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_8bit() — 8-bit → quoted-printable (php-src ext/imap/php_imap.c; #27683). */
final class imap_8bit extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_8bit');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_8bit', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_8bit', 0, 'string');
        $result = VmImapMime::eightBit($string);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_8bit() is not implemented for JIT in this compiler build (issue #27683)');
    }
}
