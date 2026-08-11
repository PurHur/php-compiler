<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_socket_server() — libc TCP/UDP listen sockets via {@see VmStreamSocketNative} (#4993).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_server)
 *
 * Z_PARAM_STR $address — soft-null DEP+coerce outside strict_types / default profile;
 * TypeError under caller strict_types or 8.4 forward profile (#30374; peer client #30314).
 */
final class stream_socket_server extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_server');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException(
                'stream_socket_server() accepts between 1 and 5 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR — soft DEP+coerce; strict_types / PROFILE≥8.4 → TypeError (#30374).
        $local = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'stream_socket_server', 0, 'address');

        $flags = VmStreamSocketNative::STREAM_SERVER_BIND | VmStreamSocketNative::STREAM_SERVER_LISTEN;
        $contextVar = null;

        if ($argc >= 4) {
            $flagsVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException(
                    'stream_socket_server() flags must be an integer in this compiler build'
                );
            }
            $flags = $flagsVar->toInt();
        }

        if ($argc >= 5) {
            $ctxVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_NULL !== $ctxVar->type && !VmStreamContext::isRepresentation($ctxVar)) {
                throw new \LogicException(
                    'stream_socket_server() context must be a stream context resource in this compiler build'
                );
            }
            if (Variable::TYPE_NULL !== $ctxVar->type) {
                $contextVar = $frame->calledArgs[4];
            }
        }

        [$result, $errno, $errstr, $socketFd] = VmStreamSocketNative::server($local, $flags, $contextVar);

        if (false === $result && 'Unable to parse local socket path' === $errstr) {
            // php-src streamsfuncs.c empty-address parse failure text (#30374).
            $errstr = 'Failed to parse address "'.$local.'"';
        }

        if ($argc >= 2) {
            $errnoOut = new Variable(Variable::TYPE_INTEGER);
            $errnoOut->int($errno);
            $frame->calledArgs[1]->byRefTarget()->copyFrom($errnoOut);
        }
        if ($argc >= 3) {
            $errstrOut = new Variable(Variable::TYPE_STRING);
            $errstrOut->string($errstr);
            $frame->calledArgs[2]->byRefTarget()->copyFrom($errstrOut);
        }

        if (false === $result) {
            // Zend soft-null empty address: "Unable to connect to  (Failed to parse address "")".
            VmStreamSocketFailure::warnConnectFailed($frame, $local, $errstr, 'stream_socket_server');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->streamHandle($result, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'stream_socket_server() is not supported for JIT/AOT in this compiler build (issue #4993)'
        );
    }
}
