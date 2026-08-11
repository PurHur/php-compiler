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
 * fsockopen() — procedural TCP socket streams (ext/standard/fsock.c parity, #8954).
 *
 * Non-persistent counterpart to {@see pfsockopen()}; connects via
 * {@see VmStreamSocketNative} (PHP-in-PHP; no runtime/*.c socket table).
 *
 * Z_PARAM_STR $hostname — soft-null DEP+coerce outside strict_types / default profile;
 * TypeError under caller strict_types or 8.4 forward profile (#30313, re-#21446; peer pfsockopen #23858).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/fsock.c PHP_FUNCTION(fsockopen)
 */
final class fsockopen extends Internal
{
    public function __construct()
    {
        parent::__construct('fsockopen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException(
                'fsockopen() accepts between 1 and 5 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR — soft DEP+coerce; strict_types / PROFILE≥8.4 → TypeError (#30313).
        $hostname = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'fsockopen', 0, 'hostname');

        $port = -1;
        if ($argc >= 2) {
            $portVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $portVar->type) {
                throw new \TypeError(
                    'fsockopen(): Argument #2 ($port) must be of type int, '
                    .VmStreamArg::debugTypeName($portVar).' given'
                );
            }
            $port = $portVar->toInt();
        }

        $errno = 0;
        $errstr = '';
        $timeout = null;
        if ($argc >= 5) {
            $timeoutVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_NULL === $timeoutVar->type) {
                $timeout = null;
            } elseif (Variable::TYPE_INTEGER === $timeoutVar->type) {
                $timeout = (float) $timeoutVar->toInt();
            } elseif (Variable::TYPE_FLOAT === $timeoutVar->type) {
                $timeout = $timeoutVar->toFloat();
            } else {
                throw new \TypeError(
                    'fsockopen(): Argument #5 ($timeout) must be of type ?float, '
                    .VmStreamArg::debugTypeName($timeoutVar).' given'
                );
            }
        }

        $remote = VmPersistentSocket::remoteUri($hostname, $port);
        $connectTimeout = null === $timeout ? 60.0 : $timeout;
        [$result, $errno, $errstr] = VmStreamSocketNative::client(
            $remote,
            $connectTimeout,
            \STREAM_CLIENT_CONNECT
        );

        if (false === $result && 'Unable to parse remote socket path' === $errstr) {
            // php-src network.c / fsock.c empty-address parse failure text.
            $errstr = 'Failed to parse address "'.$hostname.'"';
        }

        if ($argc >= 3) {
            $errnoOut = new Variable(Variable::TYPE_INTEGER);
            $errnoOut->int($errno);
            $frame->calledArgs[2]->byRefTarget()->copyFrom($errnoOut);
        }
        if ($argc >= 4) {
            $errstrOut = new Variable(Variable::TYPE_STRING);
            $errstrOut->string($errstr);
            $frame->calledArgs[3]->byRefTarget()->copyFrom($errstrOut);
        }

        if (false === $result) {
            // php-src fsock.c: Unable to connect to host:port (errstr) — empty soft-null → ":-1".
            VmStreamSocketFailure::warnConnectFailed($frame, $hostname.':'.$port, $errstr, 'fsockopen');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->streamHandle($result, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'fsockopen() is not supported for JIT/AOT in this compiler build (issue #8954)'
        );
    }
}
