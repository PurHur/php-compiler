<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_bodystruct() — MIME part structure (php-src ext/imap/php_imap.c; #27814). */
final class imap_bodystruct extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_bodystruct');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_bodystruct', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_bodystruct');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_bodystruct', 2, 'message_num');
        $section = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_bodystruct', 2, 'section');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_bodystruct() requires a VM context');
        }
        $result = VmImapCore::bodyStruct($connection, $msgNo, $section, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_bodystruct() is not implemented for JIT in this compiler build (issue #27814)');
    }
}
