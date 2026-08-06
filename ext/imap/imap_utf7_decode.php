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
 * imap_utf7_decode() — Modified UTF-7 → ISO-8859-1 (php-src ext/imap/php_imap.c; #27681).
 */
final class imap_utf7_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_utf7_decode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_utf7_decode', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_utf7_decode', 0, 'string');
        $decoded = VmImapMutf7::mutf7ToIso88591($string);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($decoded);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_utf7_decode() is not implemented for JIT in this compiler build (issue #27681)');
    }
}
