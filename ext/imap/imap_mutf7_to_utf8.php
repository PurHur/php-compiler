<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * imap_mutf7_to_utf8() — Modified UTF-7 decode (php-src ext/imap/php_imap.c; #27764).
 */
final class imap_mutf7_to_utf8 extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_mutf7_to_utf8');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_mutf7_to_utf8', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $in = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_mutf7_to_utf8', 0, 'string');
        $out = VmImapMutf7::mutf7ToUtf8($in);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_mutf7_to_utf8() is not implemented for JIT in this compiler build (issue #27764)');
    }
}
