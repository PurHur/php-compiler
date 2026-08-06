<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_rfc822_parse_headers() — parse header block to stdClass (php-src ext/imap/php_imap.c; #27682). */
final class imap_rfc822_parse_headers extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_rfc822_parse_headers');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_rfc822_parse_headers', 1, 2);
        if (null === $frame->returnVar) {
            return;
        }
        $headers = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_rfc822_parse_headers', 0, 'headers');
        $defaultHostname = 'UNKNOWN';
        if (2 === \count($frame->calledArgs)) {
            $defaultHostname = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'imap_rfc822_parse_headers',
                1,
                'default_hostname'
            );
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_rfc822_parse_headers() requires a VM context');
        }
        $frame->returnVar->copyFrom(VmImapRfc822::parseHeadersObject($headers, $defaultHostname, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_rfc822_parse_headers() is not implemented for JIT in this compiler build (issue #27682)');
    }
}
