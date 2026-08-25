<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * password_* / crypt() for compiled JIT/AOT modules (#9908, #29545, php-in-PHP).
 *
 * SSOT host/VM: {@see VmPassword}, {@see VmPasswordNative}
 * NestedJIT/AOT: `@crypt` → {@see JitLibcryptKernel} / {@see phpc_argon2_hash} (#26773, #29545) —
 * VmPasswordNative cannot NestedJIT (FFI); unbound stubs returned false under AOT.
 * php-src: ext/standard/password.c, ext/standard/crypt.c
 */
final class PasswordJitHelper
{
    /**
     * @return string empty string when hash fails (NestedJIT/?string returns were nullled under AOT — #26773)
     *
     * Do not gate on {@see VmPassword::PASSWORD_BCRYPT} with `!==` here: NestedJIT/AOT
     * class-const identity against the i64 algo param rejected bcrypt algo=1 (#26773).
     */
    public static function hashArgv(string $password, int $algo, int $cost): string
    {
        if (NestedJitCompileScope::isActive()) {
            return self::hashArgvThin($password, $algo, $cost);
        }
        $options = [];
        if ($cost > 0) {
            $options['cost'] = $cost;
        }
        $result = VmPassword::hash($password, $algo, $options);

        return false === $result ? '' : $result;
    }

    public static function verifyArgv(string $password, string $hash): int
    {
        if (NestedJitCompileScope::isActive()) {
            return self::verifyArgvThin($password, $hash);
        }

        return VmPassword::verify($password, $hash) ? 1 : 0;
    }

    public static function cryptArgv(string $password, string $salt): string
    {
        if (NestedJitCompileScope::isActive()) {
            $result = \crypt($password, $salt);
            if (!\is_string($result)) {
                return '*0';
            }
            if ('' === $result) {
                return '*0';
            }
            if ('*' === $result[0]) {
                return '*0';
            }

            return $result;
        }

        return VmPassword::crypt($password, $salt);
    }

    /**
     * password_get_info() bag for JIT/AOT bridges (#3649, #34639).
     *
     * Return type is `array` (not {@see HashTable}): NestedJIT maps class HashTable to
     * object ABI and yields an empty bag under thin AOT (#20652 / algosArgv peer).
     * NestedJIT leaf parses the hash inline — {@see VmPassword::getInfo} is outside the
     * helper translation unit and stubs to empty under thin AOT (#34639).
     * {@see \PHPCompiler\JIT\Builtin\PasswordCryptoRuntime} coerces via coerceToHashtablePtr.
     *
     * @return array{algo: ?string, algoName: string, options: array<string, mixed>}
     */
    public static function getInfoHashtable(string $hash): array
    {
        if (NestedJitCompileScope::isActive()) {
            return self::getInfoThin($hash);
        }

        return VmPassword::getInfo($hash);
    }

    /**
     * NestedJIT-safe password_get_info parse (php-src password.c php_password_algo / get_info).
     *
     * @return array{algo: ?string, algoName: string, options: array<string, int>}
     */
    private static function getInfoThin(string $hash): array
    {
        $len = \strlen($hash);
        if ($len < 3 || '$' !== $hash[0]) {
            return ['algo' => null, 'algoName' => 'unknown', 'options' => []];
        }
        // bcrypt: $2y$CC$… (60 chars) — php-src PASSWORD_BCRYPT
        if ($len >= 7 && '$' === $hash[0] && '2' === $hash[1] && 'y' === $hash[2] && '$' === $hash[3]) {
            if (60 === $len) {
                $cost = ((int) $hash[4]) * 10 + ((int) $hash[5]);
                if ($cost < 4) {
                    $cost = 10;
                }

                return [
                    'algo' => '2y',
                    'algoName' => 'bcrypt',
                    'options' => ['cost' => $cost],
                ];
            }

            return ['algo' => null, 'algoName' => 'unknown', 'options' => []];
        }
        // argon2i$ / argon2id$ — parse m=,t=,p= from the param segment (password.c)
        if ($len > 9 && \str_starts_with($hash, '$argon2i$')) {
            return self::getInfoArgon2Thin(\substr($hash, 9), 'argon2i');
        }
        if ($len > 10 && \str_starts_with($hash, '$argon2id$')) {
            return self::getInfoArgon2Thin(\substr($hash, 10), 'argon2id');
        }

        return ['algo' => null, 'algoName' => 'unknown', 'options' => []];
    }

    /**
     * @return array{algo: string, algoName: string, options: array{memory_cost: int, time_cost: int, threads: int}}
     */
    private static function getInfoArgon2Thin(string $params, string $name): array
    {
        $memoryCost = 65536;
        $timeCost = 4;
        $threads = 1;
        // NestedJIT: avoid sscanf — scan m=/t=/p= tokens by hand.
        $parts = \explode('$', $params);
        foreach ($parts as $part) {
            if (\str_starts_with($part, 'm=')) {
                $memoryCost = (int) \substr($part, 2);
            } elseif (\str_starts_with($part, 't=')) {
                $timeCost = (int) \substr($part, 2);
            } elseif (\str_starts_with($part, 'p=')) {
                $threads = (int) \substr($part, 2);
            } elseif (\str_contains($part, ',')) {
                foreach (\explode(',', $part) as $kv) {
                    if (\str_starts_with($kv, 'm=')) {
                        $memoryCost = (int) \substr($kv, 2);
                    } elseif (\str_starts_with($kv, 't=')) {
                        $timeCost = (int) \substr($kv, 2);
                    } elseif (\str_starts_with($kv, 'p=')) {
                        $threads = (int) \substr($kv, 2);
                    }
                }
            }
        }

        return [
            'algo' => $name,
            'algoName' => $name,
            'options' => [
                'memory_cost' => $memoryCost,
                'time_cost' => $timeCost,
                'threads' => $threads,
            ],
        ];
    }

    public static function needsRehashArgv(string $hash, int $algo, int $cost): int
    {
        $options = [];
        if ($cost > 0) {
            $options['cost'] = $cost;
        }

        return VmPassword::needsRehash($hash, $algo, $options) ? 1 : 0;
    }

    /**
     * password_algos() names for JIT/AOT bridges (#6195, #27658).
     *
     * Return type is `array` (not {@see HashTable}): NestedJIT maps class HashTable to
     * object ABI and yields empty under thin AOT (#20652 / hash_algos #20652 shape).
     *
     * NestedJIT leaf: literal list — {@see VmPasswordNative::passwordAlgos()} uses FFI
     * ({@see argon2Available}) which NestedJIT cannot link; argon2 is available via
     * {@see phpc_argon2_hash} (#26773), matching php-src with HAVE_ARGON2.
     *
     * @return list<string>
     */
    public static function algosArgv(): array
    {
        if (NestedJitCompileScope::isActive()) {
            return ['2y', 'argon2i', 'argon2id'];
        }

        return VmPasswordNative::passwordAlgos();
    }

    private static function hashArgvThin(string $password, int $algo, int $cost): string
    {
        // Literal type ints only — NestedJIT miscompiles class-const ternaries (#26773).
        // Argon2_i=1, Argon2_id=2 (libargon2); algo 2/3 are VmPassword::PASSWORD_ARGON2*.
        if (2 === $algo) {
            return self::argon2HashThin($password, 1);
        }
        if (3 === $algo) {
            return self::argon2HashThin($password, 2);
        }
        if (1 !== $algo) {
            return '';
        }
        $bcryptCost = $cost > 0 ? $cost : 10;
        if ($bcryptCost < 4 || $bcryptCost > 31) {
            return '';
        }
        $rnd = \random_bytes(16);
        if (!\is_string($rnd)) {
            return '';
        }
        if (16 !== \strlen($rnd)) {
            return '';
        }
        $costTwo = ($bcryptCost < 10 ? '0' : '').(string) $bcryptCost;
        $setting = '$2y$'.$costTwo.'$'.self::bcryptEncodeSalt22($rnd);
        $result = \crypt($password, $setting);
        if (!\is_string($result)) {
            return '';
        }

        return $result;
    }

    private static function argon2HashThin(string $password, int $type): string
    {
        $rnd = \random_bytes(16);
        if (!\is_string($rnd)) {
            return '';
        }
        if (16 !== \strlen($rnd)) {
            return '';
        }
        $result = \phpc_argon2_hash($password, $type, 65536, 4, 1, $rnd);
        if (!\is_string($result)) {
            return '';
        }

        return $result;
    }

    private static function verifyArgvThin(string $password, string $hash): int
    {
        if (\str_starts_with($hash, '$argon2i$')) {
            return \phpc_argon2_verify($password, $hash, 1);
        }
        if (\str_starts_with($hash, '$argon2id$')) {
            return \phpc_argon2_verify($password, $hash, 2);
        }
        if (\strlen($hash) < 29) {
            return 0;
        }
        if (!\str_starts_with($hash, '$2y$')) {
            return 0;
        }

        return \phpc_libcrypt_verify($password, $hash);
    }

    private static function bcryptEncodeSalt22(string $rnd16): string
    {
        // NestedJIT: never gate the loop on strlen($out) after `$out .=` — strlen stays 0
        // and the loop never ends (#26861). Track emitted length in `$n` instead.
        // Local literal — NestedJIT class-const string can be null (#26773).
        $itoa = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        $i = 0;
        $n = 0;
        while ($n < 22) {
            $c1 = \ord($rnd16[$i]);
            $i = $i + 1;
            $c2 = 0;
            if ($i < 16) {
                $c2 = \ord($rnd16[$i]);
                $i = $i + 1;
            }
            $out .= $itoa[$c1 >> 2];
            $n = $n + 1;
            if ($n >= 22) {
                break;
            }
            $out .= $itoa[(($c1 & 0x03) << 4) | ($c2 >> 4)];
            $n = $n + 1;
            if ($n >= 22) {
                break;
            }
            $c3 = 0;
            if ($i < 16) {
                $c3 = \ord($rnd16[$i]);
                $i = $i + 1;
            }
            $out .= $itoa[(($c2 & 0x0f) << 2) | ($c3 >> 6)];
            $n = $n + 1;
            if ($n >= 22) {
                break;
            }
            $out .= $itoa[$c3 & 0x3f];
            $n = $n + 1;
        }
        // Fixed-22 copy — avoid substr() NestedJIT edge cases (#26861 / soundex peer).
        $result = '';
        for ($j = 0; $j < 22; ++$j) {
            $result .= $out[$j];
        }

        return $result;
    }
}
