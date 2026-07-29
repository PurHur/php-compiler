<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_pkey_export() — export private key to PEM (php-src ext/openssl/xp.c; #6295).
 *
 * Reflection / named-arg params match Zend stub `key,output,passphrase,options`
 * (not InternalArgInfo `out`/`config_args`; #24492).
 */
final class openssl_pkey_export extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_export');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'openssl_pkey_export() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(
                'openssl_pkey_export() expects at most 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $passphrase = null;
        if ($argc >= 3) {
            $passphrase = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[2],
                'openssl_pkey_export',
                2,
                'passphrase'
            );
        }

        if ($argc >= 4) {
            $optionsVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $optionsVar->type && Variable::TYPE_ARRAY !== $optionsVar->type) {
                throw new \TypeError(\sprintf(
                    'openssl_pkey_export(): Argument #4 ($options) must be of type ?array, %s given',
                    match ($optionsVar->type) {
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_INTEGER => 'int',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_OBJECT => 'object',
                        default => 'mixed',
                    }
                ));
            }
            // php-src PHP_SSL_REQ_PARSE($options): encrypt_key defaults true; cipher from config.
        }

        $exported = VmOpenssl::pkeyExportPem($frame->calledArgs[0], $passphrase, $frame);
        if (false === $exported) {
            $frame->returnVar->bool(false);

            return;
        }

        $outVar = $frame->calledArgs[1]->resolveIndirect();
        $outVar->string($exported);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkey_export() is not implemented for JIT in this compiler build (issue #6295)'
        );
    }
}
