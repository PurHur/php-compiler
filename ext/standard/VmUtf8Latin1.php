<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * utf8_encode()/utf8_decode() NestedJIT/AOT SSOT (#32879).
 *
 * Peer {@see Bin2hexJitHelper} / #20452: avoid native strlen()/ord() under NestedJIT
 * helper TUs. Length via isset($s[$i]); byte ordinal via match-table.
 *
 * php-src: ext/standard/utf8.c — php_utf8_encode / php_utf8_decode
 */
final class VmUtf8Latin1
{
    /** ISO-8859-1 to UTF-8. */
    public static function encode(string $src): string
    {
        $srcLen = 0;
        while (isset($src[$srcLen])) {
            $srcLen = $srcLen + 1;
        }
        if (0 === $srcLen) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $srcLen) {
            $ch = $src[$i];
            $c = self::byteOrd($ch);
            if ($c < 0x80) {
                $out .= $ch;
            } else {
                $out .= self::byteAt(0xC0 | ($c >> 6));
                $out .= self::byteAt(0x80 | ($c & 0x3F));
            }
            $i = $i + 1;
        }

        return $out;
    }

    /**
     * UTF-8 to ISO-8859-1.
     *
     * Structured as a thin loop that delegates multi-byte handling to helpers so
     * NestedJIT of the entry stays small (a monolithic decode NestedJIT returned "").
     */
    public static function decode(string $src): string
    {
        $srcLen = 0;
        while (isset($src[$srcLen])) {
            $srcLen = $srcLen + 1;
        }
        if (0 === $srcLen) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $srcLen) {
            $ch = $src[$i];
            $c = self::byteOrd($ch);
            if ($c < 0x80) {
                $out .= $ch;
                $i = $i + 1;
                continue;
            }
            $step = self::decodeMultibyte($src, $i, $srcLen, $c);
            $out .= $step[0];
            $i = $i + $step[1];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int} appended chunk + how many source bytes consumed
     */
    private static function decodeMultibyte(string $src, int $i, int $srcLen, int $c): array
    {
        if ($c >= 0xC2 && $c <= 0xDF) {
            $i1 = $i + 1;
            if ($i1 >= $srcLen) {
                return ['?', 1];
            }
            $c1 = self::byteOrd($src[$i1]);
            if (($c1 & 0xC0) !== 0x80) {
                return ['?', 1];
            }
            $cp = (($c & 0x1F) * 64) + ($c1 & 0x3F);
            if ($cp <= 0xFF) {
                return [self::byteAt($cp), 2];
            }

            return ['?', 2];
        }
        if ($c >= 0xE0 && $c <= 0xEF) {
            $i1 = $i + 1;
            $i2 = $i + 2;
            if ($i2 >= $srcLen) {
                return ['?', 1];
            }
            $c1 = self::byteOrd($src[$i1]);
            $c2 = self::byteOrd($src[$i2]);
            if (($c1 & 0xC0) !== 0x80 || ($c2 & 0xC0) !== 0x80) {
                return ['?', 1];
            }
            $cp = (($c & 0x0F) * 4096) + (($c1 & 0x3F) * 64) + ($c2 & 0x3F);
            if ($cp >= 0x800 && $cp <= 0xFF) {
                return [self::byteAt($cp), 3];
            }

            return ['?', 3];
        }
        if ($c >= 0xF0 && $c <= 0xF4) {
            $i1 = $i + 1;
            $i2 = $i + 2;
            $i3 = $i + 3;
            if ($i3 >= $srcLen
                || (self::byteOrd($src[$i1]) & 0xC0) !== 0x80
                || (self::byteOrd($src[$i2]) & 0xC0) !== 0x80
                || (self::byteOrd($src[$i3]) & 0xC0) !== 0x80) {
                return ['?', 1];
            }

            return ['?', 4];
        }

        return ['?', 1];
    }

    /** NestedJIT-safe byte ordinal (#20452 / Bin2hexJitHelper). */
    private static function byteOrd(string $byte): int
    {
        for ($code = 0; $code < 256; $code = $code + 1) {
            if ($byte === self::byteAt($code)) {
                return $code;
            }
        }

        return 0;
    }

    private static function byteAt(int $code): string
    {
        return match ($code & 0xFF) {
            0 => "\0", 1 => "\x01", 2 => "\x02", 3 => "\x03", 4 => "\x04", 5 => "\x05",
            6 => "\x06", 7 => "\x07", 8 => "\x08", 9 => "\x09", 10 => "\x0a", 11 => "\x0b",
            12 => "\x0c", 13 => "\x0d", 14 => "\x0e", 15 => "\x0f", 16 => "\x10", 17 => "\x11",
            18 => "\x12", 19 => "\x13", 20 => "\x14", 21 => "\x15", 22 => "\x16", 23 => "\x17",
            24 => "\x18", 25 => "\x19", 26 => "\x1a", 27 => "\x1b", 28 => "\x1c", 29 => "\x1d",
            30 => "\x1e", 31 => "\x1f", 32 => ' ', 33 => '!', 34 => '"', 35 => '#', 36 => '$',
            37 => '%', 38 => '&', 39 => "'", 40 => '(', 41 => ')', 42 => '*', 43 => '+',
            44 => ',', 45 => '-', 46 => '.', 47 => '/', 48 => '0', 49 => '1', 50 => '2',
            51 => '3', 52 => '4', 53 => '5', 54 => '6', 55 => '7', 56 => '8', 57 => '9',
            58 => ':', 59 => ';', 60 => '<', 61 => '=', 62 => '>', 63 => '?', 64 => '@',
            65 => 'A', 66 => 'B', 67 => 'C', 68 => 'D', 69 => 'E', 70 => 'F', 71 => 'G',
            72 => 'H', 73 => 'I', 74 => 'J', 75 => 'K', 76 => 'L', 77 => 'M', 78 => 'N',
            79 => 'O', 80 => 'P', 81 => 'Q', 82 => 'R', 83 => 'S', 84 => 'T', 85 => 'U',
            86 => 'V', 87 => 'W', 88 => 'X', 89 => 'Y', 90 => 'Z', 91 => '[', 92 => '\\',
            93 => ']', 94 => '^', 95 => '_', 96 => '`', 97 => 'a', 98 => 'b', 99 => 'c',
            100 => 'd', 101 => 'e', 102 => 'f', 103 => 'g', 104 => 'h', 105 => 'i', 106 => 'j',
            107 => 'k', 108 => 'l', 109 => 'm', 110 => 'n', 111 => 'o', 112 => 'p', 113 => 'q',
            114 => 'r', 115 => 's', 116 => 't', 117 => 'u', 118 => 'v', 119 => 'w', 120 => 'x',
            121 => 'y', 122 => 'z', 123 => '{', 124 => '|', 125 => '}', 126 => '~', 127 => "\x7f",
            128 => "\x80", 129 => "\x81", 130 => "\x82", 131 => "\x83", 132 => "\x84", 133 => "\x85",
            134 => "\x86", 135 => "\x87", 136 => "\x88", 137 => "\x89", 138 => "\x8a", 139 => "\x8b",
            140 => "\x8c", 141 => "\x8d", 142 => "\x8e", 143 => "\x8f", 144 => "\x90", 145 => "\x91",
            146 => "\x92", 147 => "\x93", 148 => "\x94", 149 => "\x95", 150 => "\x96", 151 => "\x97",
            152 => "\x98", 153 => "\x99", 154 => "\x9a", 155 => "\x9b", 156 => "\x9c", 157 => "\x9d",
            158 => "\x9e", 159 => "\x9f", 160 => "\xa0", 161 => "\xa1", 162 => "\xa2", 163 => "\xa3",
            164 => "\xa4", 165 => "\xa5", 166 => "\xa6", 167 => "\xa7", 168 => "\xa8", 169 => "\xa9",
            170 => "\xaa", 171 => "\xab", 172 => "\xac", 173 => "\xad", 174 => "\xae", 175 => "\xaf",
            176 => "\xb0", 177 => "\xb1", 178 => "\xb2", 179 => "\xb3", 180 => "\xb4", 181 => "\xb5",
            182 => "\xb6", 183 => "\xb7", 184 => "\xb8", 185 => "\xb9", 186 => "\xba", 187 => "\xbb",
            188 => "\xbc", 189 => "\xbd", 190 => "\xbe", 191 => "\xbf", 192 => "\xc0", 193 => "\xc1",
            194 => "\xc2", 195 => "\xc3", 196 => "\xc4", 197 => "\xc5", 198 => "\xc6", 199 => "\xc7",
            200 => "\xc8", 201 => "\xc9", 202 => "\xca", 203 => "\xcb", 204 => "\xcc", 205 => "\xcd",
            206 => "\xce", 207 => "\xcf", 208 => "\xd0", 209 => "\xd1", 210 => "\xd2", 211 => "\xd3",
            212 => "\xd4", 213 => "\xd5", 214 => "\xd6", 215 => "\xd7", 216 => "\xd8", 217 => "\xd9",
            218 => "\xda", 219 => "\xdb", 220 => "\xdc", 221 => "\xdd", 222 => "\xde", 223 => "\xdf",
            224 => "\xe0", 225 => "\xe1", 226 => "\xe2", 227 => "\xe3", 228 => "\xe4", 229 => "\xe5",
            230 => "\xe6", 231 => "\xe7", 232 => "\xe8", 233 => "\xe9", 234 => "\xea", 235 => "\xeb",
            236 => "\xec", 237 => "\xed", 238 => "\xee", 239 => "\xef", 240 => "\xf0", 241 => "\xf1",
            242 => "\xf2", 243 => "\xf3", 244 => "\xf4", 245 => "\xf5", 246 => "\xf6", 247 => "\xf7",
            248 => "\xf8", 249 => "\xf9", 250 => "\xfa", 251 => "\xfb", 252 => "\xfc", 253 => "\xfd",
            254 => "\xfe", 255 => "\xff",
        };
    }
}
