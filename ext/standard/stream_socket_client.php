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
 * stream_socket_client() — libc TCP/UDP client via {@see VmStreamSocketNative} (#8097, #6815, #3202).
 *
 * Stream context socket options are read from the VM representation; routed through
 * {@see VmStreamSocketNative} (no host Zend stream wrapper delegation).
 */
final class stream_socket_client extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_client');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 6) {
            throw new \LogicException(
                'stream_socket_client() accepts between 1 and 6 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $remote = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'stream_socket_client',
            0,
            'remote_socket'
        );

        $errno = 0;
        $errstr = '';
        $timeout = 60.0;
        $flags = \STREAM_CLIENT_CONNECT;
        $contextVar = null;

        if (isset($frame->calledArgs[3])) {
            $timeoutVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL === $timeoutVar->type) {
                $timeout = 60.0;
            } elseif (Variable::TYPE_INTEGER === $timeoutVar->type) {
                $timeout = (float) $timeoutVar->toInt();
            } elseif (Variable::TYPE_FLOAT === $timeoutVar->type) {
                $timeout = $timeoutVar->toFloat();
            } else {
                throw new \LogicException(
                    'stream_socket_client() timeout must be int, float, or null in this compiler build'
                );
            }
        }

        if (isset($frame->calledArgs[4])) {
            $flagsVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException(
                    'stream_socket_client() flags must be an integer in this compiler build'
                );
            }
            $flags = $flagsVar->toInt();
        }

        if (isset($frame->calledArgs[5])) {
            $ctxVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $ctxVar->type && !VmStreamContext::isRepresentation($ctxVar)) {
                throw new \LogicException(
                    'stream_socket_client() context must be a stream context resource in this compiler build'
                );
            }
            if (Variable::TYPE_NULL !== $ctxVar->type) {
                $contextVar = $frame->calledArgs[5];
            }
        }

        [$result, $errno, $errstr, $socketFd] = VmStreamSocketNative::client(
            $remote,
            $timeout,
            $flags,
            $contextVar
        );

        if (isset($frame->calledArgs[1])) {
            $errnoOut = new Variable(Variable::TYPE_INTEGER);
            $errnoOut->int($errno);
            $frame->calledArgs[1]->copyFrom($errnoOut);
        }
        if (isset($frame->calledArgs[2])) {
            $errstrOut = new Variable(Variable::TYPE_STRING);
            $errstrOut->string($errstr);
            $frame->calledArgs[2]->copyFrom($errstrOut);
        }

        if (false === $result) {
            VmStreamSocketFailure::warnConnectFailed($frame, $remote, $errstr);
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->streamHandle($result, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'stream_socket_client() is not supported for JIT/AOT in this compiler build'
        );
    }
}
