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
 * imap_utf8_to_mutf7() — Modified UTF-7 encode (php-src ext/imap/php_imap.c; #27764).
 */
final class imap_utf8_to_mutf7 extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_utf8_to_mutf7');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_utf8_to_mutf7', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $in = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_utf8_to_mutf7', 0, 'string');
        $out = VmImapMutf7::utf8ToMutf7($in);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_utf8_to_mutf7() is not implemented for JIT in this compiler build (issue #27764)');
    }
}
