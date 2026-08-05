<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imap_headers() — per-message header summary lines (php-src ext/imap/php_imap.c; #27800). */
final class imap_headers extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_headers');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_headers', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_headers');
        $rows = VmImapCore::headers($connection);
        if (false === $rows) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmImapCore::stringListToHashTable($rows));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_headers() is not implemented for JIT in this compiler build (issue #27800)');
    }
}
