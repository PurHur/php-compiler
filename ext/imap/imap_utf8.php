<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_utf8() — MIME-encoded text → UTF-8 (php-src ext/imap/php_imap.c; #27683). */
final class imap_utf8 extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_utf8');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_utf8', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_utf8', 0, 'mime_encoded_text');
        $frame->returnVar->string(VmImapMime::utf8($text));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_utf8() is not implemented for JIT in this compiler build (issue #27683)');
    }
}
