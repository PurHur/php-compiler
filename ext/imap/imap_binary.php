<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_binary() — 8-bit → base64 (php-src ext/imap/php_imap.c; #27683). */
final class imap_binary extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_binary');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_binary', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_binary', 0, 'string');
        $result = VmImapMime::binary($string);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_binary() is not implemented for JIT in this compiler build (issue #27683)');
    }
}
