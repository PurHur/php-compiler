<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_init() — incremental digest context (php-src ext/hash/hash.c; issue #7174, #23585).
 *
 * Stub: hash_init(string $algo, int $flags = 0, string $key = "", array $options = []): HashContext
 */
final class hash_init extends HashFunction
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                $argc < 1
                    ? 'hash_init() expects at least 1 argument, %d given'
                    : 'hash_init() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $algo — non-strict null is E_DEPRECATED + '' then ValueError (#21572).
        $algo = VmString::trimFamilyStringArgForFrame($frame, 0, 'hash_init', 0, 'algo');
        $flags = 0;
        if (isset($frame->calledArgs[1])) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'hash_init', 2, 'flags');
        }
        $key = '';
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_STR $key — soft-null on 8.4 like hash_hmac $key (#21557 / #23585).
            $key = VmString::zparamStrBuiltinArgForFrame($frame, 2, 'hash_init', 3, 'key');
        }
        if (isset($frame->calledArgs[3])) {
            // Z_PARAM_ARRAY $options — accepted for stub parity; unused for sha256/sha1/md5.
            VmArray::requireArrayParam($frame->calledArgs[3], 'hash_init', 4, 'options');
        }
        $ctx = VmHashContext::init(VmReflection::requireContext($frame), $algo, $flags, $key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ctx): void {
            $ret->copyFrom($ctx);
        });
    }
}
