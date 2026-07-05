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
 * openssl_pkey_get_private() — load private key (php-src ext/openssl/xp.c; #6295).
 */
final class openssl_pkey_get_private extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_get_private');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_pkey_get_private() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $passphrase = null;
        if (2 === $argc) {
            $passVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING === $passVar->type) {
                $passphrase = $passVar->toString();
            } elseif (Variable::TYPE_NULL !== $passVar->type) {
                throw new \TypeError(\sprintf(
                    'openssl_pkey_get_private(): Argument #2 ($passphrase) must be of type ?string, %s given',
                    match ($passVar->type) {
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_INTEGER => 'int',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => $passVar->toObject()->class->name,
                        default => 'mixed',
                    }
                ));
            }
        }

        $key = VmOpenssl::pkeyGetPrivate($frame->calledArgs[0], $passphrase, $frame->vmContext, $frame);
        if (false === $key) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($key->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkey_get_private() is not implemented for JIT in this compiler build (issue #6295)'
        );
    }
}
