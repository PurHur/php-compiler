<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ob_gzhandler() — gzip output-buffer handler (ext/zlib/zlib.c, issue #4655).
 *
 * Registered for ob_start("ob_gzhandler"); direct calls mirror php-src handler modes.
 */
final class ob_gzhandler extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_gzhandler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError('ob_gzhandler() expects 1 or 2 arguments, '.$argc.' given');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ob_gzhandler', 0, 'data');
        $mode = \PHP_OUTPUT_HANDLER_CONT;
        if ($argc >= 2) {
            $mode = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'ob_gzhandler', 2, 'mode');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmObGzhandler::handle($data, $mode, $frame->vmContext));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_gzhandler() is not implemented for JIT in this compiler build');
    }
}
