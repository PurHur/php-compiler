<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * openssl_free_key() — deprecated noop (php-src ext/openssl/xp.c; issue #7268, JIT/AOT #33486).
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
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'openssl_free_key() expects exactly 1 argument, '.\count($args).' given'
            );
        }
        // Peer utf8_encode / VmEngineBuiltinDeprecation::emitJitFunction (#33486).
        if (!NestedJitCompileScope::isActive()) {
            JitBuiltinWarning::emitDeprecated($context, self::DEPRECATION);
        }
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
