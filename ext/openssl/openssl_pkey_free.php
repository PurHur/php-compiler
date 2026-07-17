<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_pkey_free() — deprecated noop alias of openssl_free_key (php-src ext/openssl/openssl.c; #20271).
 *
 * PHP 8.0+: OpenSSLAsymmetricKey objects are GC-managed; this call only triggers E_DEPRECATED
 * after a typed OpenSSLAsymmetricKey argument check (openssl.stub.php).
 */
final class openssl_pkey_free extends Internal
{
    private const DEPRECATION = 'Function openssl_pkey_free() is deprecated';

    public function __construct()
    {
        parent::__construct('openssl_pkey_free');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_pkey_free() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }

        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type || !VmOpensslObjects::isAsymmetricKey($arg)) {
            throw new \TypeError(\sprintf(
                'openssl_pkey_free(): Argument #1 ($key) must be of type OpenSSLAsymmetricKey, %s given',
                self::typeLabel($arg)
            ));
        }

        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                self::DEPRECATION,
                ErrorReporter::E_DEPRECATED,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkey_free() is not implemented for JIT in this compiler build (issue #20271)'
        );
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
