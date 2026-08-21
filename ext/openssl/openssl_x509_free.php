<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_x509_free() — deprecated noop (php-src ext/openssl/openssl.c; #20272 VM, JIT/AOT #33489).
 *
 * PHP 8.0+: OpenSSLCertificate objects are GC-managed; this call only triggers E_DEPRECATED
 * after a typed OpenSSLCertificate argument check (openssl.stub.php).
 *
 * JIT/AOT leftover #33489: catchable argc/TypeError + E_DEPRECATED + null (peer #33487
 * openssl_pkey_free / #33486 openssl_free_key). Thin AOT has no FFI — happy-path cert objects
 * still need a baked openssl_x509_read (#7268); TypeError/argc paths are the AOT gate.
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
        if (!$this->requireExactJitArgCount($context, $args, 'openssl_x509_free', 1)) {
            return self::jitReturnNull($context);
        }

        $arg = $args[0];
        if (!self::jitArgIsCertificate($arg)) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'openssl_x509_free(): Argument #1 ($certificate) must be of type OpenSSLCertificate, %s given',
                    self::jitTypeLabel($arg)
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_x509_free_te_cont');

            return self::jitReturnNull($context);
        }

        if (!NestedJitCompileScope::isActive()) {
            JitBuiltinWarning::emitDeprecated($context, self::DEPRECATION);
        }

        return self::jitReturnNull($context);
    }

    private static function jitArgIsCertificate(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_OBJECT !== $arg->type) {
            return false;
        }
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            // Runtime / opaque object slot — accept; GC noop matches Zend after Z_PARAM_OBJECT.
            return true;
        }

        return 0 === \strcasecmp($class, 'OpenSSLCertificate');
    }

    private static function jitReturnNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    private static function jitTypeLabel(JITVariable $arg): string
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
            JITVariable::TYPE_OBJECT => (null !== $arg->classUserType && '' !== $arg->classUserType)
                ? $arg->classUserType
                : 'object',
            default => 'mixed',
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
