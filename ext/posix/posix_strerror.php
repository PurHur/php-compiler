<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** posix_strerror() — errno to string (php-src ext/posix/posix.c; #7271). */
final class posix_strerror extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_strerror');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_strerror() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src ext/posix/posix.stub.php — int $error_code (#27905)
        $errno = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'posix_strerror',
            1,
            'error_code'
        );
        if ($errno < 0) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    'posix_strerror(): Unknown error '.$errno,
                    ErrorReporter::E_WARNING,
                    $frame->scriptPath,
                    $frame->callSiteLine
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string(VmPosix::strerror($errno));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('posix_strerror() requires exactly one argument');
        }

        return JitPosix::strerror($context, $args[0]);
    }
}
