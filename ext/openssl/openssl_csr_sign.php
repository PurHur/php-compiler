<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_csr_sign() — sign CSR into X.509 certificate (php-src ext/openssl/xp.c; #6421).
 */
final class openssl_csr_sign extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_sign');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 6) {
            throw new \ArgumentCountError(
                'openssl_csr_sign() expects 4 to 6 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $daysVar = $frame->calledArgs[3]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $daysVar->type) {
            throw new \TypeError(\sprintf(
                'openssl_csr_sign(): Argument #4 ($days) must be of type int, %s given',
                match ($daysVar->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }
        $days = $daysVar->toInt();

        $options = $argc >= 5 ? $frame->calledArgs[4] : null;
        $serial = 0;
        if ($argc >= 6) {
            $serialVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $serialVar->type) {
                throw new \TypeError(\sprintf(
                    'openssl_csr_sign(): Argument #6 ($serial) must be of type int, %s given',
                    match ($serialVar->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => 'object',
                        default => 'mixed',
                    }
                ));
            }
            $serial = $serialVar->toInt();
        }

        $cert = VmOpenssl::csrSign(
            $frame->calledArgs[0],
            $frame->calledArgs[1],
            $frame->calledArgs[2],
            $days,
            $options,
            $serial,
            $frame->vmContext,
            $frame
        );
        if (false === $cert) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($cert->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_csr_sign() is not implemented for JIT in this compiler build (issue #6421)'
        );
    }
}
