<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmEngineBuiltinDeprecation;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_x509_free() — deprecated noop (php-src ext/openssl/openssl.c; #20272 VM, JIT/AOT #33492).
 *
 * PHP 8.0+: OpenSSLCertificate objects are GC-managed; this call only triggers E_DEPRECATED
 * after a typed OpenSSLCertificate argument check (openssl.stub.php).
 */
final class openssl_x509_free extends Internal
{
    private const DEPRECATION = 'Function openssl_x509_free() is deprecated';

    public function __construct()
    {
        parent::__construct('openssl_x509_free');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_x509_free() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }

        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type || !VmOpensslObjects::isCertificate($arg)) {
            throw new \TypeError(\sprintf(
                'openssl_x509_free(): Argument #1 ($certificate) must be of type OpenSSLCertificate, %s given',
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
        $argc = \count($args);
        if (1 !== $argc) {
            // php-src #[\Deprecated] fires before argc check on Zend; match that order.
            VmEngineBuiltinDeprecation::emitJitFunction($context, 'openssl_x509_free');
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'openssl_x509_free() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        VmEngineBuiltinDeprecation::emitJitFunction($context, 'openssl_x509_free');

        $badLabel = self::compileTimeNonCertificateLabel($args[0]);
        if (null !== $badLabel) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_x509_free(): Argument #1 ($certificate) must be of type OpenSSLCertificate, '
                .$badLabel.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        // Object / value-box: GC no-op (OpenSSLCertificate check is VM-faithful for typed objects).
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }

    /** @return non-empty-string|null Zend type label when the arg cannot be OpenSSLCertificate */
    private static function compileTimeNonCertificateLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_HASHTABLE => 'array',
            default => null,
        };
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
