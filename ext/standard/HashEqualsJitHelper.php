<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hash_equals() timing-safe compare for compiled JIT/AOT modules (#9164, #20469, php-in-PHP).
 *
 * Logic mirrors {@see VmHash::equals} — self-contained (no VmHash / strlen / ord) so NestedJIT
 * helper units are not ExternalMethod-stubbed (#16075; peer Bin2hex #20452).
 * Length via isset-scan; mismatch via byte inequality OR (always full pass when lengths match).
 * php-src: ext/hash/hash.c — hash_equals()
 */
final class HashEqualsJitHelper
{
    /** @return bool LLVM i1 ABI; bridge coerces to i32 for __compiler_hash_equals */
    public static function equals(string $known, string $user): bool
    {
        $knownLen = 0;
        while (isset($known[$knownLen])) {
            ++$knownLen;
        }
        $userLen = 0;
        while (isset($user[$userLen])) {
            ++$userLen;
        }
        if ($knownLen !== $userLen) {
            return false;
        }
        $result = 0;
        for ($i = 0; $i < $knownLen; ++$i) {
            $result |= ($known[$i] === $user[$i]) ? 0 : 1;
        }

        return 0 === $result;
    }
}
