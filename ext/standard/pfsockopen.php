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
 * pfsockopen() — persistent TCP socket streams (ext/standard/fsock.c parity, #3384, #8107).
 *
 * VM connects via {@see VmStreamSocketNative} + {@see VmPersistentSocket} registry — no host
 * {@see \pfsockopen()} delegation (PHP-in-PHP; no runtime/*.c socket table).
 *
 * Z_PARAM_STR $hostname — soft-null DEP+coerce outside strict_types / default profile;
 * TypeError under caller strict_types or 8.4 forward profile (#30393, peer fsockopen #30313 / #23858).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/fsock.c PHP_FUNCTION(pfsockopen)
 */
final class pfsockopen extends Internal
{
    public function __construct()
    {
        parent::__construct('pfsockopen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException(
                'pfsockopen() accepts between 1 and 5 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR — soft DEP+coerce; strict_types / PROFILE≥8.4 → TypeError (#30393).
        $hostname = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'pfsockopen', 0, 'hostname');

        $port = -1;
        if ($argc >= 2) {
            $portVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $portVar->type) {
                throw new \TypeError(
                    'pfsockopen(): Argument #2 ($port) must be of type int, '
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
                    'pfsockopen(): Argument #5 ($timeout) must be of type ?float, '
                    .VmStreamArg::debugTypeName($timeoutVar).' given'
                );
            }
        }

        [$result, $errno, $errstr, $socketFd] = VmPersistentSocket::open($hostname, $port, $timeout);

        if (false === $result && 'Unable to parse remote socket path' === $errstr) {
            // php-src network.c / fsock.c empty-address parse failure text (#30393).
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
            VmStreamSocketFailure::warnConnectFailed($frame, $hostname.':'.$port, $errstr, 'pfsockopen');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->streamHandle($result, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'pfsockopen() is not supported for JIT/AOT in this compiler build (issue #3384)'
        );
    }
}
