<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_rfc822_parse_adrlist() — parse address list (php-src ext/imap/php_imap.c; #27682). */
final class imap_rfc822_parse_adrlist extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_rfc822_parse_adrlist');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_rfc822_parse_adrlist', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_rfc822_parse_adrlist', 0, 'string');
        $defaultHostname = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_rfc822_parse_adrlist', 1, 'default_hostname');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_rfc822_parse_adrlist() requires a VM context');
        }
        $addrs = VmImapRfc822::parseAdrlist($string, $defaultHostname);
        $frame->returnVar->copyFrom(VmImapRfc822::addressListToVariable($addrs, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_rfc822_parse_adrlist() is not implemented for JIT in this compiler build (issue #27682)');
    }
}
