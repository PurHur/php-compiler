<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * hash_hkdf() — RFC 5869 HKDF (VM + JIT/AOT via __compiler_hash_hkdf, issue #5025).
 *
 * php-src: ext/hash/hash_hkdf.c
 */
final class hash_hkdf extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('hash_hkdf() requires two to five arguments in this compiler build');
        }
        $algo = $frame->calledArgs[0]->resolveIndirect();
        $key = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $algo->type || Variable::TYPE_STRING !== $key->type) {
            throw new \LogicException(
                'hash_hkdf() requires string algorithm and key in this compiler build'
            );
        }
        $length = 0;
        if ($argc >= 3) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'hash_hkdf', 3, 'length');
            if ($length < 0) {
                throw new \ValueError('hash_hkdf(): Argument #3 ($length) must be greater than or equal to 0');
            }
        }
        $info = '';
        if ($argc >= 4) {
            $info = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'hash_hkdf', 4, 'info');
        }
        $salt = '';
        if (5 === $argc) {
            $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'hash_hkdf', 5, 'salt');
        }
        $algoName = strtolower($algo->toString());
        if (!\in_array($algoName, ['sha256', 'sha1', 'md5'], true)) {
            throw new \ValueError(
                'hash_hkdf(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmHash::hashHkdf(
            $algo->toString(),
            $key->toString(),
            $length,
            $info,
            $salt
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 5) {
            throw new \LogicException('hash_hkdf() requires two to five arguments in this compiler build');
        }
        $length = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2])) {
            $length = JitLongArg::lower($context, $args[2], 'hash_hkdf() length');
        }
        $info = JitStringArg::lower($context, $args[3] ?? self::emptyStringJit($context), 'hash_hkdf() info');
        $salt = JitStringArg::lower($context, $args[4] ?? self::emptyStringJit($context), 'hash_hkdf() salt');

        return JitHash::hashHkdf(
            $context,
            JitStringArg::lower($context, $args[0], 'hash_hkdf() algorithm'),
            JitStringArg::lower($context, $args[1], 'hash_hkdf() key'),
            $length,
            $info,
            $salt
        );
    }

    private static function emptyStringJit(Context $context): JITVariable
    {
        $var = new JITVariable();
        $var->type = JITVariable::TYPE_VALUE;
        $var->kind = JITVariable::KIND_VALUE;
        $var->compileTimeString = '';

        return $var;
    }
}
