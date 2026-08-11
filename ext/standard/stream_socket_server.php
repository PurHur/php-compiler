<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_socket_server() — libc TCP/UDP listen sockets via {@see VmStreamSocketNative} (#4993, #30374).
 *
 * Z_PARAM_STR $address: caller strict_types → TypeError on null; soft path DEP+coerce (#30374).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_server)
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php stream_socket_server(string $address, …)
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

        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#30374).
        $local = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
            'stream_socket_server',
            0,
            'address',
            false
        );

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
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->streamHandle($result, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 5) {
            throw new \LogicException(
                'stream_socket_server() accepts between 1 and 5 arguments in this compiler build'
            );
        }

        // Soft-null outside strict_types; strict → TypeError (#30374).
        // Early return after compile-time null TypeError — no listen path after abort
        // (AOT module verify: terminator mid-block; peer getprotobyname #30282).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[0],
                'stream_socket_server',
                0,
                'address',
                'string',
                null,
                false
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        throw new \LogicException(
            'stream_socket_server() is not supported for JIT/AOT in this compiler build (issue #4993)'
        );
    }
}
