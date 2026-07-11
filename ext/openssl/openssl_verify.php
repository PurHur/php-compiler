<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_verify() — EVP_DigestVerify (php-src ext/openssl/openssl.c; #11535).
 */
final class openssl_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_verify');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_verify() expects 3 or 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_verify', 0, 'data');
        $signature = VmOpenssl::coerceSignatureArg($frame->calledArgs[1], 'openssl_verify', 1, 'signature');
        $keyPem = VmOpenssl::coercePkeyPem($frame->calledArgs[2], 'openssl_verify', 2, 'key');
        $algorithm = OpensslConstants::OPENSSL_ALGO_SHA1;
        if (4 === $argc) {
            $algorithm = self::coerceAlgorithmArg($frame->calledArgs[3], 'openssl_verify', 3, 'algorithm');
        }

        $frame->returnVar->int(VmOpenssl::verify($data, $signature, $keyPem, $algorithm, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_verify() expects 3 or 4 arguments, '.$argc.' given'
            );
        }

        return JitOpensslSign::verify(
            $context,
            $args[0],
            $args[1],
            $args[2],
            $args[3] ?? null
        );
    }

    private static function coerceAlgorithmArg(\PHPCompiler\VM\Variable $var, string $function, int $argIndex, string $paramName): int|string
    {
        $var = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (\PHPCompiler\VM\Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int|string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                \PHPCompiler\VM\Variable::TYPE_BOOLEAN => 'bool',
                \PHPCompiler\VM\Variable::TYPE_FLOAT => 'float',
                \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                \PHPCompiler\VM\Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            }
        ));
    }
}
