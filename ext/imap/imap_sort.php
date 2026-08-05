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

/** imap_sort() — sorted message numbers (php-src ext/imap/php_imap.c; #27784). */
final class imap_sort extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_sort');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_sort', 3, 6);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_sort');
        $criteria = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_sort', 2, 'criteria');
        $reverse = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_sort', 3, 'reverse');
        $flags = 0;
        if ($argc >= 4) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_sort', 4, 'flags');
        }
        $searchCriteria = null;
        if ($argc >= 5) {
            $searchCriteria = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'imap_sort', 4, 'search_criteria');
        }
        $charset = null;
        if ($argc >= 6) {
            $charset = VmString::coerceStringBuiltinArg($frame->calledArgs[5], 'imap_sort', 5, 'charset');
        }
        $hits = VmImapCore::sort($connection, $criteria, $reverse, $flags, $searchCriteria, $charset);
        if (false === $hits) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmImapCore::intListToHashTable($hits));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_sort() is not implemented for JIT in this compiler build (issue #27784)');
    }
}
