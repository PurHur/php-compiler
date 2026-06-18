<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_random_pseudo_bytes() — OS CSPRNG with optional $crypto_strong out-param (#4994).
 *
 * php-src: ext/standard/random.c — delegates to php_random_bytes(); sets *cstrong = 1 on success.
 * VM: {@see VmRandomNative::randomBytes()} (getrandom/urandom FFI). JIT/AOT: {@see JitRandomBytes} / /dev/urandom read.
 */
final class openssl_random_pseudo_bytes extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_random_pseudo_bytes');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException(
                'openssl_random_pseudo_bytes() expects one or two arguments in this compiler build'
            );
        }
        $length = self::parseLength($frame->calledArgs[0]->resolveIndirect());
        $bytes = VmString::randomBytes($length);
        if ($argc >= 2) {
            $frame->calledArgs[1]->resolveIndirect()->bool(true);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($bytes);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException(
                'openssl_random_pseudo_bytes() expects one or two arguments in this compiler build'
            );
        }
        $length = JitSleep::zParamLong($context, $args[0], 'openssl_random_pseudo_bytes', 1, 'length');
        $result = JitRandomBytes::generate($context, $length);
        if (2 === $argc) {
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                JitValueBox::valuePtrFromVariable($context, $args[1]),
                $context->getTypeFromString('int32')->constInt(1, false)
            );
        }

        return $result;
    }

    /**
     * Z_PARAM_LONG length (ext/standard/random.c; same rules as random_bytes()).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    private static function parseLength(Variable $var): int
    {
        return VmMath::parseIntBuiltinArg($var, 'openssl_random_pseudo_bytes', 1, 'length');
    }
}
