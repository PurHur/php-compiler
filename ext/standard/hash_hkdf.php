<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
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
        // Z_PARAM_STR $algo / $key — null coerces to "" then empty-key ValueError (php-src hash_hkdf.c; #19341).
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'hash_hkdf', 0, 'algo');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash_hkdf', 1, 'key');
        VmString::rejectEmptyBuiltinStringArg($key, 'hash_hkdf', 1, 'key');
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
        $algoName = strtolower($algo);
        if (!\in_array($algoName, ['sha256', 'sha1', 'md5'], true)) {
            throw new \ValueError(
                'hash_hkdf(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmHash::hashHkdf(
            $algo,
            $key,
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
        $info = JitStringBuiltinArg::lower(
            $context,
            $args[3] ?? self::emptyStringJit($context),
            'hash_hkdf',
            3,
            'info'
        );
        $salt = JitStringBuiltinArg::lower(
            $context,
            $args[4] ?? self::emptyStringJit($context),
            'hash_hkdf',
            4,
            'salt'
        );
        $key = JitStringBuiltinArg::lower($context, $args[1], 'hash_hkdf', 1, 'key');
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[1],
            $key,
            'hash_hkdf(): Argument #2 ($key) cannot be empty'
        );

        return JitHash::hashHkdf(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'hash_hkdf', 0, 'algo'),
            $key,
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
