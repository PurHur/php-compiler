<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_csr_get_subject() — DN from CSR (php-src ext/openssl/xp.c; #6421 VM, JIT/AOT #32692).
 *
 * JIT/AOT leftover #33541: catchable argc/TypeError paths (peer openssl_csr_get_public_key #33514).
 * Happy-path PEM fold remains {@see JitOpensslX509::csrGetSubject}.
 */
final class openssl_csr_get_subject extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_get_subject');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_csr_get_subject() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $shortnames = true;
        if (2 === $argc) {
            $shortnames = VmOpenssl::coerceBoolArg(
                $frame->calledArgs[1],
                'openssl_csr_get_subject',
                1,
                'short_names'
            );
        }

        $subject = VmOpenssl::csrGetSubject($frame->calledArgs[0], $shortnames, $frame);
        if (false === $subject) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom($subject);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'openssl_csr_get_subject() expects 1 or 2 arguments, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_get_subject_argc_cont');

            return self::jitReturnFalse($context);
        }

        $badLabel = self::compileTimeNonCsrLabel($args[0]);
        if (null !== $badLabel) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_csr_get_subject(): Argument #1 ($csr) must be of type '
                .'OpenSSLCertificateSigningRequest|string, '.$badLabel.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_get_subject_te_cont');

            return self::jitReturnFalse($context);
        }

        return JitOpensslX509::csrGetSubject($context, $args[0], $args[1] ?? null);
    }

    /**
     * VM union via resolveCsrPem: OpenSSLCertificateSigningRequest|string.
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonCsrLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }

        // Also reject compile-time array literals that arrive as TYPE_VALUE.
        if (\is_array($arg->compileTimeArray)) {
            return 'array';
        }

        // TYPE_VALUE / unknown: leave to JitOpensslX509 (heredoc locals are VALUE
        // with compileTimeString; rejecting as "mixed" breaks PEM fold #32692).
        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_HASHTABLE => 'array',
            JITVariable::TYPE_STRING => null,
            JITVariable::TYPE_OBJECT => self::objectTypeErrorLabel($arg),
            default => null,
        };
    }

    private static function objectTypeErrorLabel(JITVariable $arg): ?string
    {
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            return null;
        }
        if (0 === \strcasecmp($class, 'OpenSSLCertificateSigningRequest')) {
            return null;
        }

        return $class;
    }

    private static function jitReturnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
