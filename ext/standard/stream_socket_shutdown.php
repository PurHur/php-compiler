<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_socket_shutdown() — shutdown socket stream read/write side (issue #6043).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_shutdown)
 */
final class stream_socket_shutdown extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_shutdown');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_socket_shutdown() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_shutdown',
            1
        );
        $how = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_socket_shutdown',
            2,
            'how'
        );

        $frame->returnVar->bool(VmStreamSocketShutdown::shutdown($handle, $how));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'stream_socket_shutdown() is not supported for JIT/AOT in this compiler build (issue #6043)'
        );
    }
}
