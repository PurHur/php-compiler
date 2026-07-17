<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * ftp_set_option() — php-src ext/ftp/php_ftp.c; issue #20060.
 */
final class ftp_set_option extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_set_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_set_option() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_set_option');
        $option = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ftp_set_option', 2, 'option');
        $valueVar = $frame->calledArgs[2]->resolveIndirect();
        if (FtpConstants::FTP_TIMEOUT_SEC === $option) {
            $value = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'ftp_set_option', 3, 'value');
        } elseif (Variable::TYPE_BOOL === $valueVar->type) {
            $value = $valueVar->toBool();
        } else {
            $value = (bool) VmMath::parseIntBuiltinArgForFrame($frame, 2, 'ftp_set_option', 3, 'value');
        }
        $frame->returnVar->bool(VmFtpCore::setOption($connection, $option, $value));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_set_option() is not implemented for JIT in this compiler build (issue #20060)');
    }
}
