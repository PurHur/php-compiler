<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** posix_mkfifo() — create FIFO special file (php-src ext/posix/posix.c; #6667). */
final class posix_mkfifo extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_mkfifo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'posix_mkfifo() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        InternalStrictArg::requireString($frame, 0, 'posix_mkfifo', 'filename');
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'posix_mkfifo', 0, 'filename');
        $mode = InternalStrictArg::requireInt($frame, 1, 'posix_mkfifo', 'permissions')->toInt();
        if (0 !== ($mode & ~07777) && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                'posix_mkfifo(): Invalid mode specified '.$mode,
                ErrorReporter::E_WARNING,
                $frame->scriptPath,
                $frame->callSiteLine
            );
            $frame->returnVar->bool(false);

            return;
        }
        $ok = VmPosix::mkfifo($path, $mode);
        if (!$ok && null !== $frame->vmContext) {
            $errno = VmPosix::getLastError();
            if (0 !== $errno) {
                $frame->vmContext->errors->triggerError(
                    'posix_mkfifo(): '.VmPosix::strerror($errno),
                    ErrorReporter::E_WARNING,
                    $frame->scriptPath,
                    $frame->callSiteLine
                );
            }
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_mkfifo() is not implemented for JIT in this compiler build (issue #6667)');
    }
}
