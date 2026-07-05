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
 * openssl_spki_new() — Netscape SPKAC generation (php-src ext/openssl/openssl.c; #8690).
 */
final class openssl_spki_new extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_spki_new');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_spki_new() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $challenge = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_spki_new', 1, 'challenge');
        $algorithm = OpensslConstants::OPENSSL_ALGO_MD5;
        if (3 === $argc) {
            $algorithm = self::coerceAlgorithmArg($frame->calledArgs[2], 'openssl_spki_new', 2, 'digest_algo');
        }

        $spkac = VmOpenssl::spkiNew($frame->calledArgs[0], $challenge, $algorithm, $frame);
        if (false === $spkac) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($spkac);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_spki_new() is not implemented for JIT in this compiler build (issue #8690)'
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
