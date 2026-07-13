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
 * openssl_x509_verify() — X.509 signature verification (php-src ext/openssl/x509.c; #6595).
 */
final class openssl_x509_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_verify');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_x509_verify() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $flags = 0;
        if (3 === $argc) {
            $flags = self::intArg($frame->calledArgs[2], 3, 'flags');
        }

        $frame->returnVar->copyFrom(
            VmOpensslObjects::verifyCertificate(
                $frame->vmContext,
                $frame->calledArgs[0],
                $frame->calledArgs[1],
                $flags,
                $frame
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_x509_verify() is not implemented for JIT in this compiler build (issue #6595)'
        );
    }

    private static function intArg(Variable $arg, int $position, string $paramName): int
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_INTEGER === $arg->type) {
            return $arg->toInt();
        }
        if (Variable::TYPE_DOUBLE === $arg->type) {
            return (int) $arg->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $arg->type) {
            return $arg->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $arg->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return (int) $arg->toString();
        }

        throw new \TypeError(\sprintf(
            'openssl_x509_verify(): Argument #%d ($%s) must be of type int, %s given',
            $position,
            $paramName,
            match ($arg->type) {
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => $arg->toObject()->class->name,
                default => 'mixed',
            }
        ));
    }
}
