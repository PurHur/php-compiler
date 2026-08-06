<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_mime_header_decode() — RFC 2047 header fragments (php-src ext/imap/php_imap.c; #27683). */
final class imap_mime_header_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_mime_header_decode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_mime_header_decode', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_mime_header_decode', 0, 'string');
        $parts = VmImapMime::mimeHeaderDecodeParts($string);
        if (false === $parts) {
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_mime_header_decode() requires a VM context');
        }
        $frame->returnVar->copyFrom(VmImapMime::mimeHeaderPartsToVariable($parts, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_mime_header_decode() is not implemented for JIT in this compiler build (issue #27683)');
    }
}
