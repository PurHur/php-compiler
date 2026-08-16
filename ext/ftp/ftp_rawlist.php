<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * ftp_rawlist() — php-src ext/ftp/php_ftp.c; issue #20033.
 */
final class ftp_rawlist extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_rawlist');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_rawlist() expects at least 2 arguments and at most 3, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_rawlist');
        $directory = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_rawlist', 1, 'directory');
        $recursive = false;
        if ($argc >= 3) {
            $recursive = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $result = VmFtpCore::rawlist($connection, $directory, $recursive);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($result);

    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpList::invokeRawlist($context, ...$args);
    }
}
