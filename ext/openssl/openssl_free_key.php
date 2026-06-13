<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * openssl_free_key() — deprecated noop (php-src ext/openssl/xp.c; issue #7268).
 *
 * PHP 8.0+: OpenSSLAsymmetricKey objects are GC-managed; this call only triggers E_DEPRECATED.
 */
final class openssl_free_key extends Internal
{
    private const DEPRECATION = 'Function openssl_free_key() is deprecated';

    public function __construct()
    {
        parent::__construct('openssl_free_key');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_free_key() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
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
            'openssl_free_key() is not implemented for JIT in this compiler build (issue #7268)'
        );
    }
}
