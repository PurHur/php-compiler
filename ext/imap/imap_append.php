<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_append() — append message to mailbox (php-src ext/imap/php_imap.c; #27814). */
final class imap_append extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_append');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_append', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_append');
        $folder = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_append', 1, 'folder');
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_append', 2, 'message');
        $options = null;
        if ($argc >= 4 && !self::isNullArg($frame->calledArgs[3])) {
            $options = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'imap_append', 3, 'options');
        }
        $internalDate = null;
        if ($argc >= 5 && !self::isNullArg($frame->calledArgs[4])) {
            $internalDate = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'imap_append', 4, 'internal_date');
        }
        $frame->returnVar->bool(VmImapCore::append($connection, $folder, $message, $options, $internalDate));
    }

    private static function isNullArg(Variable $var): bool
    {
        return Variable::TYPE_NULL === $var->resolveIndirect()->type;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_append() is not implemented for JIT in this compiler build (issue #27814)');
    }
}
