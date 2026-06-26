<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native hash() / hash_hmac() digests for VM bootstrap (issue #4790).
 *
 * Native sha256/sha1/md5 digests for VM bootstrap (mirrors StringHashCryptoJit logic, issue #4790).
 */
final class VmHashNative
{
    private const SHA256_DIGEST_SIZE = 32;
    private const SHA1_DIGEST_SIZE = 20;
    private const MD5_DIGEST_SIZE = 16;

    /** @var list<int> */
    private const SHA256_K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];

    public static function hash(string $algo, string $data, bool $raw = false): string|false
    {
        $id = self::algoId($algo);
        if (0 === $id) {
            return false;
        }
        if ((9 === $id || 10 === $id) && !VmHashXxh::available()) {
            return false;
        }
        $digest = self::digest($id, $data);
        if (null === $digest) {
            return false;
        }

        return self::resultString($id, $digest, $raw);
    }

    public static function hashHmac(string $algo, string $data, string $key, bool $raw = false): string|false
    {
        $id = self::algoId($algo);
        if (0 === $id) {
            return false;
        }
        $digest = self::hmac($id, $data, $key);

        return self::resultString($id, $digest, $raw);
    }

    /**
     * hash_hkdf() — RFC 5869 HKDF via HMAC (ext/hash/hash_hkdf.c; issue #5025).
     */
    public static function hashHkdf(
        string $algo,
        string $key,
        int $length = 0,
        string $info = '',
        string $salt = ''
    ): string {
        $id = self::algoId($algo);
        if (0 === $id) {
            return '';
        }
        if ($length < 0) {
            return '';
        }
        $hlen = self::digestLen($id);
        $okmLen = 0 === $length ? $hlen : $length;
        $prk = self::hkdfExtract($id, $key, $salt, $hlen);

        return self::hkdfExpand($id, $prk, $info, $okmLen, $hlen);
    }

    private static function hkdfExtract(int $algo, string $key, string $salt, int $hlen): string
    {
        if ('' === $salt) {
            $salt = \str_repeat("\0", $hlen);
        }

        return self::digestBytesToString(self::hmac($algo, $key, $salt));
    }

    private static function hkdfExpand(int $algo, string $prk, string $info, int $length, int $hlen): string
    {
        $blocks = (int) (($length + $hlen - 1) / $hlen);
        $okm = '';
        $t = '';
        for ($i = 1; $i <= $blocks; $i++) {
            $t = self::digestBytesToString(self::hmac($algo, $t.$info.\chr($i), $prk));
            $okm .= $t;
        }

        return \substr($okm, 0, $length);
    }

    /**
     * hash_pbkdf2() — PBKDF2 via HMAC (ext/hash/hash_pbkdf2.c; issue #6186).
     */
    public static function hashPbkdf2(
        string $algo,
        string $password,
        string $salt,
        int $iterations,
        int $length = 0,
        bool $raw = false
    ): string {
        $id = self::algoId($algo);
        if (0 === $id) {
            return '';
        }
        $hlen = self::digestLen($id);
        $dklen = 0 === $length ? $hlen : $length;
        $blocks = (int) (($dklen + $hlen - 1) / $hlen);
        $derived = '';
        $written = 0;
        for ($block = 1; $block <= $blocks; $block++) {
            $t = self::pbkdf2F($id, $password, $salt, $block, $iterations);
            $tBin = self::digestBytesToString($t);
            $copy = \min($hlen, $dklen - $written);
            $derived .= \substr($tBin, 0, $copy);
            $written += $copy;
        }

        if ($raw) {
            return $derived;
        }

        $hex = self::hexEncode($derived);
        if ($length > 0) {
            return \substr($hex, 0, $length);
        }

        return $hex;
    }

    /** 0 unknown, 1 sha256, 2 sha1, 3 md5, 4 crc32b, 5 crc32, 6 adler32, 7 fnv132, 8 fnv1a32, 9 xxh3, 10 xxh128 */
    private static function algoId(string $algo): int
    {
        if (self::eqCi($algo, 'sha256')) {
            return 1;
        }
        if (self::eqCi($algo, 'sha1')) {
            return 2;
        }
        if (self::eqCi($algo, 'md5')) {
            return 3;
        }
        if (self::eqCi($algo, 'crc32b')) {
            return 4;
        }
        if (self::eqCi($algo, 'crc32')) {
            return 5;
        }
        if (self::eqCi($algo, 'adler32')) {
            return 6;
        }
        if (self::eqCi($algo, 'fnv132')) {
            return 7;
        }
        if (self::eqCi($algo, 'fnv1a32')) {
            return 8;
        }
        if (self::eqCi($algo, 'xxh3')) {
            return 9;
        }
        if (self::eqCi($algo, 'xxh128')) {
            return 10;
        }

        return 0;
    }

    private static function eqCi(string $a, string $b): bool
    {
        if (\strlen($a) !== \strlen($b)) {
            return false;
        }
        $len = \strlen($a);
        for ($i = 0; $i < $len; $i++) {
            $ca = \ord($a[$i]);
            $cb = \ord($b[$i]);
            if ($ca >= 0x41 && $ca <= 0x5A) {
                $ca += 32;
            }
            if ($cb >= 0x41 && $cb <= 0x5A) {
                $cb += 32;
            }
            if ($ca !== $cb) {
                return false;
            }
        }

        return true;
    }

    private static function u32(int $x): int
    {
        return $x & 0xFFFFFFFF;
    }

    private static function swapEndian32(int $v): int
    {
        $v = self::u32($v);

        return self::u32(
            (($v & 0xFF) << 24)
            | (($v & 0xFF00) << 8)
            | (($v >> 8) & 0xFF00)
            | (($v >> 24) & 0xFF)
        );
    }

    private static function u32Not(int $x): int
    {
        return self::u32($x) ^ 0xFFFFFFFF;
    }

    private static function rotr(int $x, int $n): int
    {
        $x = self::u32($x);

        return self::u32(($x >> $n) | ($x << (32 - $n)));
    }

    private static function rotl(int $x, int $n): int
    {
        $x = self::u32($x);

        return self::u32(($x << $n) | ($x >> (32 - $n)));
    }

    private static function hexEncode(string $bin): string
    {
        static $hex = '0123456789abcdef';
        $out = '';
        $len = \strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $byte = \ord($bin[$i]);
            $out .= $hex[($byte >> 4) & 0x0F].$hex[$byte & 0x0F];
        }

        return $out;
    }

    private static function digestLen(int $algo): int
    {
        if (10 === $algo) {
            return 16;
        }
        if (9 === $algo) {
            return 8;
        }
        if ($algo >= 4 && $algo <= 8) {
            return 4;
        }
        if (1 === $algo) {
            return self::SHA256_DIGEST_SIZE;
        }
        if (2 === $algo) {
            return self::SHA1_DIGEST_SIZE;
        }

        return self::MD5_DIGEST_SIZE;
    }

    /** @param list<int> $digest */
    private static function digestBytesToString(array $digest): string
    {
        $out = '';
        $count = \count($digest);
        for ($i = 0; $i < $count; $i++) {
            $out .= \chr($digest[$i]);
        }

        return $out;
    }

    /** @param list<int> $digest */
    private static function resultString(int $algo, array $digest, bool $raw): string
    {
        $bin = self::digestBytesToString($digest);
        if ($raw) {
            return $bin;
        }

        return self::hexEncode($bin);
    }

    /** @return list<int>|null */
    private static function digest(int $algo, string $data): ?array
    {
        if (1 === $algo) {
            return self::sha256($data);
        }
        if (2 === $algo) {
            return self::sha1($data);
        }
        if (3 === $algo) {
            return self::md5($data);
        }
        if (4 === $algo) {
            return VmHashNonCrypto::digestBytes(VmHashNonCrypto::crc32b($data));
        }
        if (5 === $algo) {
            return VmHashNonCrypto::digestBytes(self::swapEndian32(VmHashNonCrypto::crc32($data)));
        }
        if (6 === $algo) {
            return VmHashNonCrypto::digestBytes(VmHashNonCrypto::adler32($data));
        }
        if (7 === $algo) {
            return VmHashNonCrypto::digestBytes(VmHashNonCrypto::fnv132($data));
        }
        if (8 === $algo) {
            return VmHashNonCrypto::digestBytes(VmHashNonCrypto::fnv1a32($data));
        }
        if (9 === $algo) {
            return VmHashXxh::xxh3DigestBytes($data);
        }
        if (10 === $algo) {
            return VmHashXxh::xxh128DigestBytes($data);
        }

        return self::md5($data);
    }

    /* --- SHA-256 --- */

    private static function sha256Ch(int $x, int $y, int $z): int
    {
        $x = self::u32($x);

        return self::u32(($x & $y) ^ (self::u32Not($x) & $z));
    }

    private static function sha256Maj(int $x, int $y, int $z): int
    {
        return self::u32(($x & $y) ^ ($x & $z) ^ ($y & $z));
    }

    private static function sha256Ep0(int $x): int
    {
        return self::u32(self::rotr($x, 2) ^ self::rotr($x, 13) ^ self::rotr($x, 22));
    }

    private static function sha256Ep1(int $x): int
    {
        return self::u32(self::rotr($x, 6) ^ self::rotr($x, 11) ^ self::rotr($x, 25));
    }

    private static function sha256Sig0(int $x): int
    {
        $x = self::u32($x);

        return self::u32(self::rotr($x, 7) ^ self::rotr($x, 18) ^ ($x >> 3));
    }

    private static function sha256Sig1(int $x): int
    {
        $x = self::u32($x);

        return self::u32(self::rotr($x, 17) ^ self::rotr($x, 19) ^ ($x >> 10));
    }

    /**
     * @param array{data: string, datalen: int, bitlen: int, state: list<int>} $ctx
     */
    private static function sha256Transform(array &$ctx, string $block): void
    {
        /** @var list<int> $m */
        $m = \array_values(\unpack('N16', $block));
        for ($i = 16; $i < 64; ++$i) {
            $m[$i] = self::u32(
                self::sha256Sig1($m[$i - 2]) + $m[$i - 7] + self::sha256Sig0($m[$i - 15]) + $m[$i - 16]
            );
        }
        $a = $ctx['state'][0];
        $b = $ctx['state'][1];
        $c = $ctx['state'][2];
        $d = $ctx['state'][3];
        $e = $ctx['state'][4];
        $f = $ctx['state'][5];
        $g = $ctx['state'][6];
        $h = $ctx['state'][7];
        for ($i = 0; $i < 64; ++$i) {
            $t1 = self::u32($h + self::sha256Ep1($e) + self::sha256Ch($e, $f, $g) + self::SHA256_K[$i] + $m[$i]);
            $t2 = self::u32(self::sha256Ep0($a) + self::sha256Maj($a, $b, $c));
            $h = $g;
            $g = $f;
            $f = $e;
            $e = self::u32($d + $t1);
            $d = $c;
            $c = $b;
            $b = $a;
            $a = self::u32($t1 + $t2);
        }
        $ctx['state'][0] = self::u32($ctx['state'][0] + $a);
        $ctx['state'][1] = self::u32($ctx['state'][1] + $b);
        $ctx['state'][2] = self::u32($ctx['state'][2] + $c);
        $ctx['state'][3] = self::u32($ctx['state'][3] + $d);
        $ctx['state'][4] = self::u32($ctx['state'][4] + $e);
        $ctx['state'][5] = self::u32($ctx['state'][5] + $f);
        $ctx['state'][6] = self::u32($ctx['state'][6] + $g);
        $ctx['state'][7] = self::u32($ctx['state'][7] + $h);
    }

    /**
     * @param array{data: string, datalen: int, bitlen: int, state: list<int>} $ctx
     */
    private static function sha256Update(array &$ctx, string $data): void
    {
        $len = \strlen($data);
        for ($i = 0; $i < $len; ++$i) {
            $ctx['data'][$ctx['datalen']] = $data[$i];
            $ctx['datalen']++;
            if (64 === $ctx['datalen']) {
                self::sha256Transform($ctx, $ctx['data']);
                $ctx['bitlen'] += 512;
                $ctx['datalen'] = 0;
            }
        }
    }

    /** @return list<int> */
    private static function sha256(string $data): array
    {
        $ctx = [
            'data' => \str_repeat("\0", 64),
            'datalen' => 0,
            'bitlen' => 0,
            'state' => [
                0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
            ],
        ];
        self::sha256Update($ctx, $data);
        $datalen = $ctx['datalen'];
        $ctx['bitlen'] += $datalen * 8;
        $padlen = $datalen < 56 ? 56 - $datalen : 120 - $datalen;
        $pad = "\x80".\str_repeat("\0", 127);
        self::sha256Update($ctx, \substr($pad, 0, $padlen));
        $bits = '';
        for ($i = 0; $i < 8; $i++) {
            $bits .= \chr((int) (($ctx['bitlen'] >> (56 - $i * 8)) & 0xFF));
        }
        self::sha256Update($ctx, $bits);
        $hash = [];
        for ($i = 0; $i < 4; ++$i) {
            $hash[$i] = (self::u32($ctx['state'][0]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 4] = (self::u32($ctx['state'][1]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 8] = (self::u32($ctx['state'][2]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 12] = (self::u32($ctx['state'][3]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 16] = (self::u32($ctx['state'][4]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 20] = (self::u32($ctx['state'][5]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 24] = (self::u32($ctx['state'][6]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 28] = (self::u32($ctx['state'][7]) >> (24 - $i * 8)) & 0xFF;
        }

        return $hash;
    }

    /* --- MD5 --- */

    private static function md5F(int $x, int $y, int $z): int
    {
        $x = self::u32($x);

        return self::u32(($x & $y) | (self::u32Not($x) & $z));
    }

    private static function md5G(int $x, int $y, int $z): int
    {
        $z = self::u32($z);

        return self::u32(($x & $z) | ($y & self::u32Not($z)));
    }

    private static function md5H(int $x, int $y, int $z): int
    {
        return self::u32($x ^ $y ^ $z);
    }

    private static function md5I(int $x, int $y, int $z): int
    {
        return self::u32($y ^ ($x | self::u32Not($z)));
    }

    /** @param list<int> $output */
    private static function md5Encode(array &$output, array $input, int $len): void
    {
        for ($i = 0, $j = 0; $j < $len; $i++, $j += 4) {
            $output[$j] = $input[$i] & 0xFF;
            $output[$j + 1] = ($input[$i] >> 8) & 0xFF;
            $output[$j + 2] = ($input[$i] >> 16) & 0xFF;
            $output[$j + 3] = ($input[$i] >> 24) & 0xFF;
        }
    }

    /** @return list<int> */
    private static function md5Decode(string $input, int $len): array
    {
        $output = [];
        for ($i = 0, $j = 0; $j < $len; $i++, $j += 4) {
            $output[$i] = self::u32(
                \ord($input[$j])
                | (\ord($input[$j + 1]) << 8)
                | (\ord($input[$j + 2]) << 16)
                | (\ord($input[$j + 3]) << 24)
            );
        }

        return $output;
    }

    /**
     * @param list<int> $state
     * @return list<int>
     */
    private static function md5Transform(array $state, string $block): array
    {
        $a = $state[0];
        $b = $state[1];
        $c = $state[2];
        $d = $state[3];
        $x = self::md5Decode($block, 64);

        $md5Step = static function (
            int &$a,
            int $b,
            int $c,
            int $d,
            int $x,
            int $s,
            int $ac,
            callable $fn
        ): void {
            $a = VmHashNative::u32($a + $fn($b, $c, $d) + $x + $ac);
            $a = VmHashNative::u32(VmHashNative::rotl($a, $s) + $b);
        };

        $md5Step($a, $b, $c, $d, $x[0], 7, 0xd76aa478, [self::class, 'md5F']);
        $md5Step($d, $a, $b, $c, $x[1], 12, 0xe8c7b756, [self::class, 'md5F']);
        $md5Step($c, $d, $a, $b, $x[2], 17, 0x242070db, [self::class, 'md5F']);
        $md5Step($b, $c, $d, $a, $x[3], 22, 0xc1bdceee, [self::class, 'md5F']);
        $md5Step($a, $b, $c, $d, $x[4], 7, 0xf57c0faf, [self::class, 'md5F']);
        $md5Step($d, $a, $b, $c, $x[5], 12, 0x4787c62a, [self::class, 'md5F']);
        $md5Step($c, $d, $a, $b, $x[6], 17, 0xa8304613, [self::class, 'md5F']);
        $md5Step($b, $c, $d, $a, $x[7], 22, 0xfd469501, [self::class, 'md5F']);
        $md5Step($a, $b, $c, $d, $x[8], 7, 0x698098d8, [self::class, 'md5F']);
        $md5Step($d, $a, $b, $c, $x[9], 12, 0x8b44f7af, [self::class, 'md5F']);
        $md5Step($c, $d, $a, $b, $x[10], 17, 0xffff5bb1, [self::class, 'md5F']);
        $md5Step($b, $c, $d, $a, $x[11], 22, 0x895cd7be, [self::class, 'md5F']);
        $md5Step($a, $b, $c, $d, $x[12], 7, 0x6b901122, [self::class, 'md5F']);
        $md5Step($d, $a, $b, $c, $x[13], 12, 0xfd987193, [self::class, 'md5F']);
        $md5Step($c, $d, $a, $b, $x[14], 17, 0xa679438e, [self::class, 'md5F']);
        $md5Step($b, $c, $d, $a, $x[15], 22, 0x49b40821, [self::class, 'md5F']);

        $md5Step($a, $b, $c, $d, $x[1], 5, 0xf61e2562, [self::class, 'md5G']);
        $md5Step($d, $a, $b, $c, $x[6], 9, 0xc040b340, [self::class, 'md5G']);
        $md5Step($c, $d, $a, $b, $x[11], 14, 0x265e5a51, [self::class, 'md5G']);
        $md5Step($b, $c, $d, $a, $x[0], 20, 0xe9b6c7aa, [self::class, 'md5G']);
        $md5Step($a, $b, $c, $d, $x[5], 5, 0xd62f105d, [self::class, 'md5G']);
        $md5Step($d, $a, $b, $c, $x[10], 9, 0x02441453, [self::class, 'md5G']);
        $md5Step($c, $d, $a, $b, $x[15], 14, 0xd8a1e681, [self::class, 'md5G']);
        $md5Step($b, $c, $d, $a, $x[4], 20, 0xe7d3fbc8, [self::class, 'md5G']);
        $md5Step($a, $b, $c, $d, $x[9], 5, 0x21e1cde6, [self::class, 'md5G']);
        $md5Step($d, $a, $b, $c, $x[14], 9, 0xc33707d6, [self::class, 'md5G']);
        $md5Step($c, $d, $a, $b, $x[3], 14, 0xf4d50d87, [self::class, 'md5G']);
        $md5Step($b, $c, $d, $a, $x[8], 20, 0x455a14ed, [self::class, 'md5G']);
        $md5Step($a, $b, $c, $d, $x[13], 5, 0xa9e3e905, [self::class, 'md5G']);
        $md5Step($d, $a, $b, $c, $x[2], 9, 0xfcefa3f8, [self::class, 'md5G']);
        $md5Step($c, $d, $a, $b, $x[7], 14, 0x676f02d9, [self::class, 'md5G']);
        $md5Step($b, $c, $d, $a, $x[12], 20, 0x8d2a4c8a, [self::class, 'md5G']);

        $md5Step($a, $b, $c, $d, $x[5], 4, 0xfffa3942, [self::class, 'md5H']);
        $md5Step($d, $a, $b, $c, $x[8], 11, 0x8771f681, [self::class, 'md5H']);
        $md5Step($c, $d, $a, $b, $x[11], 16, 0x6d9d6122, [self::class, 'md5H']);
        $md5Step($b, $c, $d, $a, $x[14], 23, 0xfde5380c, [self::class, 'md5H']);
        $md5Step($a, $b, $c, $d, $x[1], 4, 0xa4beea44, [self::class, 'md5H']);
        $md5Step($d, $a, $b, $c, $x[4], 11, 0x4bdecfa9, [self::class, 'md5H']);
        $md5Step($c, $d, $a, $b, $x[7], 16, 0xf6bb4b60, [self::class, 'md5H']);
        $md5Step($b, $c, $d, $a, $x[10], 23, 0xbebfbc70, [self::class, 'md5H']);
        $md5Step($a, $b, $c, $d, $x[13], 4, 0x289b7ec6, [self::class, 'md5H']);
        $md5Step($d, $a, $b, $c, $x[0], 11, 0xeaa127fa, [self::class, 'md5H']);
        $md5Step($c, $d, $a, $b, $x[3], 16, 0xd4ef3085, [self::class, 'md5H']);
        $md5Step($b, $c, $d, $a, $x[6], 23, 0x04881d05, [self::class, 'md5H']);
        $md5Step($a, $b, $c, $d, $x[9], 4, 0xd9d4d039, [self::class, 'md5H']);
        $md5Step($d, $a, $b, $c, $x[12], 11, 0xe6db99e5, [self::class, 'md5H']);
        $md5Step($c, $d, $a, $b, $x[15], 16, 0x1fa27cf8, [self::class, 'md5H']);
        $md5Step($b, $c, $d, $a, $x[2], 23, 0xc4ac5665, [self::class, 'md5H']);

        $md5Step($a, $b, $c, $d, $x[0], 6, 0xf4292244, [self::class, 'md5I']);
        $md5Step($d, $a, $b, $c, $x[7], 10, 0x432aff97, [self::class, 'md5I']);
        $md5Step($c, $d, $a, $b, $x[14], 15, 0xab9423a7, [self::class, 'md5I']);
        $md5Step($b, $c, $d, $a, $x[5], 21, 0xfc93a039, [self::class, 'md5I']);
        $md5Step($a, $b, $c, $d, $x[12], 6, 0x655b59c3, [self::class, 'md5I']);
        $md5Step($d, $a, $b, $c, $x[3], 10, 0x8f0ccc92, [self::class, 'md5I']);
        $md5Step($c, $d, $a, $b, $x[10], 15, 0xffeff47d, [self::class, 'md5I']);
        $md5Step($b, $c, $d, $a, $x[1], 21, 0x85845dd1, [self::class, 'md5I']);
        $md5Step($a, $b, $c, $d, $x[8], 6, 0x6fa87e4f, [self::class, 'md5I']);
        $md5Step($d, $a, $b, $c, $x[15], 10, 0xfe2ce6e0, [self::class, 'md5I']);
        $md5Step($c, $d, $a, $b, $x[6], 15, 0xa3014314, [self::class, 'md5I']);
        $md5Step($b, $c, $d, $a, $x[13], 21, 0x4e0811a1, [self::class, 'md5I']);
        $md5Step($a, $b, $c, $d, $x[4], 6, 0xf7537e82, [self::class, 'md5I']);
        $md5Step($d, $a, $b, $c, $x[11], 10, 0xbd3af235, [self::class, 'md5I']);
        $md5Step($c, $d, $a, $b, $x[2], 15, 0x2ad7d2bb, [self::class, 'md5I']);
        $md5Step($b, $c, $d, $a, $x[9], 21, 0xeb86d391, [self::class, 'md5I']);

        return [
            self::u32($state[0] + $a),
            self::u32($state[1] + $b),
            self::u32($state[2] + $c),
            self::u32($state[3] + $d),
        ];
    }

    /**
     * @param array{count: list<int>, state: list<int>, buffer: string} $ctx
     */
    private static function md5Update(array &$ctx, string $input): void
    {
        $len = \strlen($input);
        $index = ($ctx['count'][0] >> 3) & 0x3F;
        $ctx['count'][0] = self::u32($ctx['count'][0] + ($len << 3));
        if ($ctx['count'][0] < ($len << 3)) {
            $ctx['count'][1]++;
        }
        $ctx['count'][1] = self::u32($ctx['count'][1] + ($len >> 29));
        $partLen = 64 - $index;
        $i = 0;
        if ($len >= $partLen) {
            $ctx['buffer'] = \substr($ctx['buffer'], 0, $index)
                .\substr($input, 0, $partLen);
            $ctx['state'] = self::md5Transform($ctx['state'], $ctx['buffer']);
            for ($i = $partLen; $i + 63 < $len; $i += 64) {
                $ctx['state'] = self::md5Transform($ctx['state'], \substr($input, $i, 64));
            }
            $index = 0;
            $ctx['buffer'] = \str_repeat("\0", 64);
        }
        $ctx['buffer'] = \substr($ctx['buffer'], 0, $index).\substr($input, $i);
        if (\strlen($ctx['buffer']) < 64) {
            $ctx['buffer'] .= \str_repeat("\0", 64 - \strlen($ctx['buffer']));
        }
    }

    /** @return list<int> */
    private static function md5(string $data): array
    {
        $ctx = [
            'count' => [0, 0],
            'state' => [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476],
            'buffer' => \str_repeat("\0", 64),
        ];
        self::md5Update($ctx, $data);
        $bits = \array_fill(0, 8, 0);
        self::md5Encode($bits, $ctx['count'], 8);
        $index = ($ctx['count'][0] >> 3) & 0x3F;
        $padLen = $index < 56 ? 56 - $index : 120 - $index;
        self::md5Update($ctx, "\x80");
        if ($padLen > 1) {
            self::md5Update($ctx, \str_repeat("\0", $padLen - 1));
        }
        $bitsStr = '';
        foreach ($bits as $byte) {
            $bitsStr .= \chr($byte);
        }
        self::md5Update($ctx, $bitsStr);
        $digest = \array_fill(0, 16, 0);
        self::md5Encode($digest, $ctx['state'], 16);

        return $digest;
    }

    /* --- SHA-1 --- */

    /**
     * @param array{state: list<int>, count: list<int>, buffer: string} $ctx
     */
    private static function sha1Transform(array &$ctx, string $buffer): void
    {
        $w = self::md5Decode($buffer, 64);
        for ($i = 0; $i < 16; $i++) {
            $w[$i] = self::u32(
                (($w[$i] << 24) & 0xFF000000)
                | (($w[$i] << 8) & 0x00FF0000)
                | (($w[$i] >> 8) & 0x0000FF00)
                | (($w[$i] >> 24) & 0x000000FF)
            );
        }
        for ($i = 16; $i < 80; $i++) {
            $w[$i] = self::rotl($w[$i - 3] ^ $w[$i - 8] ^ $w[$i - 14] ^ $w[$i - 16], 1);
        }
        $a = $ctx['state'][0];
        $b = $ctx['state'][1];
        $c = $ctx['state'][2];
        $d = $ctx['state'][3];
        $e = $ctx['state'][4];
        for ($i = 0; $i < 20; $i++) {
            $f = self::u32(($b & $c) | (self::u32Not($b) & $d));
            $temp = self::u32(self::rotl($a, 5) + $f + $e + $w[$i] + 0x5A827999);
            $e = $d;
            $d = $c;
            $c = self::rotl($b, 30);
            $b = $a;
            $a = $temp;
        }
        for ($i = 20; $i < 40; $i++) {
            $f = self::u32($b ^ $c ^ $d);
            $temp = self::u32(self::rotl($a, 5) + $f + $e + $w[$i] + 0x6ED9EBA1);
            $e = $d;
            $d = $c;
            $c = self::rotl($b, 30);
            $b = $a;
            $a = $temp;
        }
        for ($i = 40; $i < 60; $i++) {
            $f = self::u32(($b & $c) | ($b & $d) | ($c & $d));
            $temp = self::u32(self::rotl($a, 5) + $f + $e + $w[$i] + 0x8F1BBCDC);
            $e = $d;
            $d = $c;
            $c = self::rotl($b, 30);
            $b = $a;
            $a = $temp;
        }
        for ($i = 60; $i < 80; $i++) {
            $f = self::u32($b ^ $c ^ $d);
            $temp = self::u32(self::rotl($a, 5) + $f + $e + $w[$i] + 0xCA62C1D6);
            $e = $d;
            $d = $c;
            $c = self::rotl($b, 30);
            $b = $a;
            $a = $temp;
        }
        $ctx['state'][0] = self::u32($ctx['state'][0] + $a);
        $ctx['state'][1] = self::u32($ctx['state'][1] + $b);
        $ctx['state'][2] = self::u32($ctx['state'][2] + $c);
        $ctx['state'][3] = self::u32($ctx['state'][3] + $d);
        $ctx['state'][4] = self::u32($ctx['state'][4] + $e);
    }

    /**
     * @param array{state: list<int>, count: list<int>, buffer: string} $ctx
     */
    private static function sha1Update(array &$ctx, string $data): void
    {
        $len = \strlen($data);
        $j = ($ctx['count'][0] >> 3) & 63;
        $ctx['count'][0] = self::u32($ctx['count'][0] + ($len << 3));
        if ($ctx['count'][0] < ($len << 3)) {
            $ctx['count'][1]++;
        }
        $ctx['count'][1] = self::u32($ctx['count'][1] + ($len >> 29));
        $i = 0;
        if (($j + $len) > 63) {
            $first = 64 - $j;
            $ctx['buffer'] = \substr($ctx['buffer'], 0, $j).\substr($data, 0, $first);
            self::sha1Transform($ctx, $ctx['buffer']);
            for ($i = $first; $i + 63 < $len; $i += 64) {
                self::sha1Transform($ctx, \substr($data, $i, 64));
            }
            $j = 0;
            $ctx['buffer'] = \str_repeat("\0", 64);
        }
        $ctx['buffer'] = \substr($ctx['buffer'], 0, $j).\substr($data, $i);
        if (\strlen($ctx['buffer']) < 64) {
            $ctx['buffer'] .= \str_repeat("\0", 64 - \strlen($ctx['buffer']));
        }
    }

    /** @return list<int> */
    private static function sha1(string $data): array
    {
        $ctx = [
            'state' => [0x67452301, 0xEFCDAB89, 0x98BADCFE, 0x10325476, 0xC3D2E1F0],
            'count' => [0, 0],
            'buffer' => \str_repeat("\0", 64),
        ];
        self::sha1Update($ctx, $data);
        $finalcount = [];
        for ($i = 0; $i < 8; $i++) {
            $finalcount[$i] = ($ctx['count'][$i >= 4 ? 0 : 1] >> ((3 - ($i & 3)) * 8)) & 255;
        }
        self::sha1Update($ctx, "\x80");
        while (($ctx['count'][0] & 504) !== 448) {
            self::sha1Update($ctx, "\0");
        }
        $bitsStr = '';
        foreach ($finalcount as $byte) {
            $bitsStr .= \chr($byte);
        }
        self::sha1Update($ctx, $bitsStr);
        $digest = [];
        for ($i = 0; $i < 20; $i++) {
            $digest[$i] = ($ctx['state'][$i >> 2] >> ((3 - ($i & 3)) * 8)) & 255;
        }

        return $digest;
    }

    /* --- HMAC --- */

    /**
     * PBKDF2 F function — HMAC-based block derivation (RFC 2898; mirrors __phpc_hc_pbkdf2_f).
     *
     * @return list<int>
     */
    private static function pbkdf2F(
        int $algo,
        string $password,
        string $salt,
        int $blockIndex,
        int $iterations
    ): array {
        $dlen = self::digestLen($algo);
        $saltBlock = $salt.\pack('N', $blockIndex);
        $out = self::hmac($algo, $saltBlock, $password);
        $u = $out;
        for ($i = 1; $i < $iterations; $i++) {
            $u = self::hmac($algo, self::digestBytesToString($u), $password);
            for ($j = 0; $j < $dlen; $j++) {
                $out[$j] ^= $u[$j];
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function hmac(int $algo, string $data, string $key): array
    {
        $kPad = \array_fill(0, 64, 0);
        $dlen = self::digestLen($algo);
        if (\strlen($key) > 64) {
            $tk = self::digest($algo, $key);
            for ($i = 0; $i < $dlen; $i++) {
                $kPad[$i] = $tk[$i];
            }
        } else {
            $keyLen = \strlen($key);
            for ($i = 0; $i < $keyLen; $i++) {
                $kPad[$i] = \ord($key[$i]);
            }
        }
        for ($i = 0; $i < 64; $i++) {
            $kPad[$i] ^= 0x36;
        }
        $innerBuf = '';
        for ($i = 0; $i < 64; $i++) {
            $innerBuf .= \chr($kPad[$i]);
        }
        $innerBuf .= $data;
        $inner = self::digest($algo, $innerBuf);
        for ($i = 0; $i < 64; $i++) {
            $kPad[$i] ^= (0x36 ^ 0x5C);
        }
        $outerBuf = '';
        for ($i = 0; $i < 64; $i++) {
            $outerBuf .= \chr($kPad[$i]);
        }
        for ($i = 0; $i < $dlen; $i++) {
            $outerBuf .= \chr($inner[$i]);
        }

        return self::digest($algo, $outerBuf);
    }

    /* --- Incremental HashContext (ext/hash/hash.c; issue #7174) --- */

    /** 0 unknown, 1 sha256, 2 sha1, 3 md5 */
    public static function resolveAlgoId(string $algo): int
    {
        return self::algoId($algo);
    }

    /** Canonical registered algorithm name for HashContext::__debugInfo() (php-src ops->algo). */
    public static function resolveAlgoName(int $algoId): string
    {
        if (1 === $algoId) {
            return 'sha256';
        }
        if (2 === $algoId) {
            return 'sha1';
        }
        if (3 === $algoId) {
            return 'md5';
        }

        throw new \LogicException('Unsupported incremental hash algorithm id: '.$algoId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function incrementalCreate(int $algoId): array
    {
        if (1 === $algoId) {
            return [
                'data' => \str_repeat("\0", 64),
                'datalen' => 0,
                'bitlen' => 0,
                'state' => [
                    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
                ],
            ];
        }
        if (2 === $algoId) {
            return [
                'state' => [0x67452301, 0xEFCDAB89, 0x98BADCFE, 0x10325476, 0xC3D2E1F0],
                'count' => [0, 0],
                'buffer' => \str_repeat("\0", 64),
            ];
        }
        if (3 === $algoId) {
            return [
                'count' => [0, 0],
                'state' => [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476],
                'buffer' => \str_repeat("\0", 64),
            ];
        }

        throw new \LogicException('Unsupported incremental hash algorithm id: '.$algoId);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function incrementalUpdate(int $algoId, array &$ctx, string $data): void
    {
        if (1 === $algoId) {
            self::sha256Update($ctx, $data);

            return;
        }
        if (2 === $algoId) {
            self::sha1Update($ctx, $data);

            return;
        }
        if (3 === $algoId) {
            self::md5Update($ctx, $data);

            return;
        }

        throw new \LogicException('Unsupported incremental hash algorithm id: '.$algoId);
    }

    /**
     * @param array<string, mixed> $ctx
     *
     * @return list<int>
     */
    public static function incrementalDigest(int $algoId, array $ctx): array
    {
        if (1 === $algoId) {
            return self::sha256Finalize($ctx);
        }
        if (2 === $algoId) {
            return self::sha1Finalize($ctx);
        }
        if (3 === $algoId) {
            return self::md5Finalize($ctx);
        }

        throw new \LogicException('Unsupported incremental hash algorithm id: '.$algoId);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function incrementalFinal(int $algoId, array $ctx, bool $raw = false): string
    {
        return self::resultString($algoId, self::incrementalDigest($algoId, $ctx), $raw);
    }

    /**
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    public static function incrementalCopy(array $ctx): array
    {
        $copy = [];
        foreach ($ctx as $key => $value) {
            $copy[$key] = \is_array($value) ? [...$value] : $value;
        }

        return $copy;
    }

    /**
     * @param array{data: string, datalen: int, bitlen: int, state: list<int>} $ctx
     *
     * @return list<int>
     */
    private static function sha256Finalize(array $ctx): array
    {
        $work = self::incrementalCopy($ctx);
        $datalen = $work['datalen'];
        $work['bitlen'] += $datalen * 8;
        $padlen = $datalen < 56 ? 56 - $datalen : 120 - $datalen;
        $pad = "\x80".\str_repeat("\0", 127);
        self::sha256Update($work, \substr($pad, 0, $padlen));
        $bits = '';
        for ($i = 0; $i < 8; $i++) {
            $bits .= \chr((int) (($work['bitlen'] >> (56 - $i * 8)) & 0xFF));
        }
        self::sha256Update($work, $bits);
        $hash = [];
        for ($i = 0; $i < 4; ++$i) {
            $hash[$i] = (self::u32($work['state'][0]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 4] = (self::u32($work['state'][1]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 8] = (self::u32($work['state'][2]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 12] = (self::u32($work['state'][3]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 16] = (self::u32($work['state'][4]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 20] = (self::u32($work['state'][5]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 24] = (self::u32($work['state'][6]) >> (24 - $i * 8)) & 0xFF;
            $hash[$i + 28] = (self::u32($work['state'][7]) >> (24 - $i * 8)) & 0xFF;
        }

        return $hash;
    }

    /**
     * @param array{count: list<int>, state: list<int>, buffer: string} $ctx
     *
     * @return list<int>
     */
    private static function md5Finalize(array $ctx): array
    {
        $work = self::incrementalCopy($ctx);
        $bits = \array_fill(0, 8, 0);
        self::md5Encode($bits, $work['count'], 8);
        $index = ($work['count'][0] >> 3) & 0x3F;
        $padLen = $index < 56 ? 56 - $index : 120 - $index;
        self::md5Update($work, "\x80");
        if ($padLen > 1) {
            self::md5Update($work, \str_repeat("\0", $padLen - 1));
        }
        $bitsStr = '';
        foreach ($bits as $byte) {
            $bitsStr .= \chr($byte);
        }
        self::md5Update($work, $bitsStr);
        $digest = \array_fill(0, 16, 0);
        self::md5Encode($digest, $work['state'], 16);

        return $digest;
    }

    /**
     * @param array{state: list<int>, count: list<int>, buffer: string} $ctx
     *
     * @return list<int>
     */
    private static function sha1Finalize(array $ctx): array
    {
        $work = self::incrementalCopy($ctx);
        $finalcount = [];
        for ($i = 0; $i < 8; $i++) {
            $finalcount[$i] = ($work['count'][$i >= 4 ? 0 : 1] >> ((3 - ($i & 3)) * 8)) & 255;
        }
        self::sha1Update($work, "\x80");
        while (($work['count'][0] & 504) !== 448) {
            self::sha1Update($work, "\0");
        }
        $bitsStr = '';
        foreach ($finalcount as $byte) {
            $bitsStr .= \chr($byte);
        }
        self::sha1Update($work, $bitsStr);
        $digest = [];
        for ($i = 0; $i < 20; $i++) {
            $digest[$i] = ($work['state'][$i >> 2] >> ((3 - ($i & 3)) * 8)) & 255;
        }

        return $digest;
    }
}
