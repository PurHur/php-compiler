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
 * imap_utf7_encode() — ISO-8859-1 → Modified UTF-7 (php-src ext/imap/php_imap.c; #27681).
 */
final class imap_utf7_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_utf7_encode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_utf7_encode', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_utf7_encode', 0, 'string');
        $frame->returnVar->string(VmImapMutf7::iso88591ToMutf7($string));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_utf7_encode() is not implemented for JIT in this compiler build (issue #27681)');
    }
}
