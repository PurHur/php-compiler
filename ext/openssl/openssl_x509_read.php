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
 * openssl_x509_read() — parse PEM into OpenSSLCertificate (php-src ext/openssl/xp.c; #7268, #6274).
 *
 * JIT/AOT: argc/TypeError (#33497); happy-path PEM/cert → OpenSSLCertificate (#34048).
 */
final class openssl_x509_read extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_read');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_x509_read() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(
            VmOpensslObjects::readCertificate($frame->vmContext, $frame->calledArgs[0])
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'openssl_x509_read', 1)) {
            return self::jitReturnNull($context);
        }

        $badLabel = self::compileTimeNonCertificateOrStringLabel($args[0]);
        if (null !== $badLabel) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_x509_read(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, '
                .$badLabel.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_x509_read_te_cont');

            return self::jitReturnNull($context);
        }

        return JitOpensslX509Read::invoke($context, $args[0]);
    }

    /** @return non-empty-string|null Zend type label when the arg cannot be OpenSSLCertificate|string */
    private static function compileTimeNonCertificateOrStringLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_HASHTABLE => 'array',
            default => null,
        };
    }

    private static function jitReturnNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
