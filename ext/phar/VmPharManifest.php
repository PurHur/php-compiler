<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

/**
 * Classic PHAR manifest format (php-src ext/phar/phar.c phar_flush / phar_parse_pharfile).
 *
 * Layout after stub: u32 manifest_len | manifest payload | file bytes | digest | u32 sig_flags | GBMB
 *
 * (#21712, #21713, #21714)
 */
final class VmPharManifest
{
    private const API_NODIR = 0x1100;
    private const API_DIR = 0x1110;
    private const HDR_SIGNATURE = 0x00010000;
    private const ENT_PERM_FILE = 0x000001a4; // 0644 - matches common Zend create
    private const ENT_PERM_DIR = 0x000001ff;
    private const GBMB = 'GBMB';
    private const HALT_TOKEN = '__HALT_COMPILER()';

    /**
     * @param array<string, string>                        $files
     * @param array<string, true>                          $dirs
     * @param array<string, array{perms?: int, flags?: int}> $fileAttrs
     *
     * @return array{binary: string, sigFlags: int, signatureHex: string}
     */
    public static function build(
        string $stub,
        array $files,
        array $dirs = [],
        string $alias = '',
        bool $hasMetadata = false,
        mixed $metadata = null,
        int $sigFlags = 0,
        ?string $sigPrivateKey = null,
        array $fileAttrs = [],
        int $timestamp = 0
    ): array {
        $stub = self::normalizeStub($stub);
        if (0 === $sigFlags) {
            $sigFlags = VmPhar::SIG_SHA256;
        }
        VmPhar::assertSignatureAlgorithm($sigFlags);

        $hasDirs = [] !== $dirs;
        $entries = [];
        foreach ($dirs as $name => $_) {
            $name = \rtrim(\str_replace('\\', '/', (string) $name), '/');
            if ('' === $name || self::isReserved($name)) {
                continue;
            }
            $entries[] = [
                'name' => $name,
                'is_dir' => true,
                'data' => '',
                'perms' => $fileAttrs[$name]['perms'] ?? self::ENT_PERM_DIR,
            ];
        }
        foreach ($files as $name => $data) {
            $name = \ltrim(\str_replace('\\', '/', (string) $name), '/');
            if ('' === $name || self::isReserved($name)) {
                continue;
            }
            $entries[] = [
                'name' => $name,
                'is_dir' => false,
                'data' => (string) $data,
                'perms' => $fileAttrs[$name]['perms'] ?? self::ENT_PERM_FILE,
            ];
        }
        if ([] === $entries) {
            throw new \UnexpectedValueException('phar error: cannot create empty classic phar archive');
        }

        $metaStr = '';
        if ($hasMetadata) {
            $metaStr = \serialize($metadata);
        }

        $entryBlob = '';
        $fileBlob = '';
        foreach ($entries as $ent) {
            $fname = $ent['name'];
            $isDir = $ent['is_dir'];
            $data = $ent['data'];
            $nameForLen = $isDir ? $fname.'/' : $fname;
            $entryBlob .= self::u32(\strlen($nameForLen));
            $entryBlob .= $nameForLen;
            $crc = $isDir ? 0 : self::crc32u($data);
            $size = $isDir ? 0 : \strlen($data);
            $flags = (int) $ent['perms'];
            $entryBlob .= self::u32($size);
            $entryBlob .= self::u32($timestamp);
            $entryBlob .= self::u32($size);
            $entryBlob .= self::u32($crc);
            $entryBlob .= self::u32($flags);
            $entryBlob .= self::u32(0);
            if (!$isDir) {
                $fileBlob .= $data;
            }
        }

        $aliasLen = \strlen($alias);
        $manifestLen = 4 + 2 + 4 + 4 + $aliasLen + 4 + \strlen($metaStr) + \strlen($entryBlob);

        $api = $hasDirs ? self::API_DIR : self::API_NODIR;
        $globalFlags = self::HDR_SIGNATURE;

        $header18 = self::u32($manifestLen)
            .self::u32(\count($entries))
            .self::apiBytes($api)
            .self::u32($globalFlags)
            .self::u32($aliasLen);

        $manifestAfterLen = \substr($header18, 4)
            .$alias
            .self::u32(\strlen($metaStr))
            .$metaStr
            .$entryBlob;

        if (\strlen($manifestAfterLen) !== $manifestLen) {
            $manifestLen = \strlen($manifestAfterLen);
            $header18 = self::u32($manifestLen).\substr($header18, 4);
            $manifestAfterLen = \substr($header18, 4)
                .$alias
                .self::u32(\strlen($metaStr))
                .$metaStr
                .$entryBlob;
        }

        $unsigned = $stub.self::u32($manifestLen).$manifestAfterLen.$fileBlob;
        [$rawDigest, $sigFlags, $signatureHex] = self::sign($unsigned, $sigFlags, $sigPrivateKey);
        $binary = $unsigned.$rawDigest.self::u32($sigFlags).self::GBMB;

        return [
            'binary' => $binary,
            'sigFlags' => $sigFlags,
            'signatureHex' => $signatureHex,
        ];
    }

    /**
     * @return array{
     *   stub: string,
     *   files: array<string, string>,
     *   dirs: array<string, true>,
     *   alias: string,
     *   hasMetadata: bool,
     *   metadata: mixed,
     *   sigFlags: int,
     *   signatureHex: string
     * }|null
     */
    public static function tryParse(string $binary): ?array
    {
        $haltPos = \strpos($binary, self::HALT_TOKEN.';');
        if (false === $haltPos) {
            return null;
        }
        $stubEnd = \strpos($binary, '?'.'>', $haltPos);
        if (false === $stubEnd) {
            return null;
        }
        $stubEnd += 2;
        // optional CR/LF after close-php tag
        while ($stubEnd < \strlen($binary) && ("\r" === $binary[$stubEnd] || "\n" === $binary[$stubEnd])) {
            ++$stubEnd;
        }
        if ($stubEnd + 4 > \strlen($binary)) {
            return null;
        }
        $manifestLen = self::readU32($binary, $stubEnd);
        $payloadStart = $stubEnd + 4;
        if ($manifestLen < 14 || $payloadStart + $manifestLen > \strlen($binary)) {
            return null;
        }
        if ($manifestLen > 1048576 * 100) {
            return null;
        }
        if (!self::hasGbmbFooter($binary)) {
            return null;
        }
        $payload = \substr($binary, $payloadStart, $manifestLen);
        $off = 0;
        $count = self::readU32($payload, $off);
        $off += 4;
        if ($count < 1 || $off + 2 > \strlen($payload)) {
            return null;
        }
        $apiHi = \ord($payload[$off]);
        $apiLo = \ord($payload[$off + 1]);
        $api = ($apiHi << 8) | $apiLo;
        $off += 2;
        if (($api & 0xFFF0) < 0x1000) {
            return null;
        }
        $flags = self::readU32($payload, $off);
        $off += 4;
        $aliasLen = self::readU32($payload, $off);
        $off += 4;
        if ($aliasLen > \strlen($payload) - $off) {
            return null;
        }
        $alias = 0 === $aliasLen ? '' : \substr($payload, $off, $aliasLen);
        $off += $aliasLen;
        if ($off + 4 > \strlen($payload)) {
            return null;
        }
        $metaLen = self::readU32($payload, $off);
        $off += 4;
        if ($metaLen > \strlen($payload) - $off) {
            return null;
        }
        $metaRaw = 0 === $metaLen ? '' : \substr($payload, $off, $metaLen);
        $off += $metaLen;
        $hasMetadata = $metaLen > 0;
        $metadata = null;
        if ($hasMetadata) {
            $metadata = @\unserialize($metaRaw);
        }

        $files = [];
        $dirs = [];
        $fileSizes = [];
        for ($i = 0; $i < $count; ++$i) {
            if ($off + 4 > \strlen($payload)) {
                return null;
            }
            $nameLen = self::readU32($payload, $off);
            $off += 4;
            if ($nameLen < 1 || $off + $nameLen + 24 > \strlen($payload)) {
                return null;
            }
            $name = \substr($payload, $off, $nameLen);
            $off += $nameLen;
            $isDir = \str_ends_with($name, '/');
            if ($isDir) {
                $name = \rtrim($name, '/');
            }
            $uncompressed = self::readU32($payload, $off);
            $off += 4;
            $off += 4;
            $compressed = self::readU32($payload, $off);
            $off += 4;
            $off += 4;
            $off += 4;
            $entryMetaLen = self::readU32($payload, $off);
            $off += 4;
            if ($entryMetaLen > 0) {
                if ($off + $entryMetaLen > \strlen($payload)) {
                    return null;
                }
                $off += $entryMetaLen;
            }
            if ($isDir) {
                $dirs[$name] = true;
            } else {
                $fileSizes[$name] = $compressed;
                $files[$name] = '';
            }
        }

        $dataStart = $payloadStart + $manifestLen;
        $sigFlags = 0;
        $signatureHex = '';
        $dataEnd = \strlen($binary);
        if (($flags & self::HDR_SIGNATURE) !== 0 || self::hasGbmbFooter($binary)) {
            $parsedSig = self::parseSignatureFooter($binary);
            if (null === $parsedSig) {
                return null;
            }
            $sigFlags = $parsedSig['sigFlags'];
            $signatureHex = $parsedSig['signatureHex'];
            $dataEnd = $parsedSig['dataEnd'];
        }
        $cursor = $dataStart;
        foreach ($fileSizes as $name => $size) {
            if ($cursor + $size > $dataEnd) {
                return null;
            }
            $files[$name] = \substr($binary, $cursor, $size);
            $cursor += $size;
        }

        return [
            'stub' => \substr($binary, 0, $stubEnd),
            'files' => $files,
            'dirs' => $dirs,
            'alias' => $alias,
            'hasMetadata' => $hasMetadata,
            'metadata' => $metadata,
            'sigFlags' => $sigFlags,
            'signatureHex' => $signatureHex,
        ];
    }

    public static function looksLikeClassic(string $binary): bool
    {
        return null !== self::tryParse($binary);
    }

    public static function normalizeStub(string $stub): string
    {
        if (!\str_contains($stub, self::HALT_TOKEN)) {
            return $stub;
        }
        $close = '?'.'>';
        if (\preg_match('/'.\preg_quote(self::HALT_TOKEN, '/').'\s*;\s*\?\>\s*$/', $stub)) {
            return \rtrim($stub)."\r\n";
        }
        if (\preg_match('/'.\preg_quote(self::HALT_TOKEN, '/').'\s*;\s*$/', $stub)) {
            return \preg_replace(
                '/'.\preg_quote(self::HALT_TOKEN, '/').'\s*;\s*$/',
                self::HALT_TOKEN.'; '.$close,
                $stub
            )."\r\n";
        }

        return $stub;
    }

    /** @return array{0: string, 1: int, 2: string} rawDigest, sigFlags, hex */
    private static function sign(string $unsigned, int $sigFlags, ?string $privateKey): array
    {
        if (\in_array($sigFlags, [VmPhar::SIG_OPENSSL, VmPhar::SIG_OPENSSL_SHA256, VmPhar::SIG_OPENSSL_SHA512], true)) {
            if (null === $privateKey || '' === $privateKey) {
                throw new \PharException('no private key specified');
            }
            $digestAlgo = match ($sigFlags) {
                VmPhar::SIG_OPENSSL_SHA256 => OPENSSL_ALGO_SHA256,
                VmPhar::SIG_OPENSSL_SHA512 => OPENSSL_ALGO_SHA512,
                default => OPENSSL_ALGO_SHA1,
            };
            $signature = '';
            if (!\openssl_sign($unsigned, $signature, $privateKey, $digestAlgo)) {
                throw new \PharException('openssl signing failed');
            }

            return [$signature, $sigFlags, \strtoupper(\bin2hex($signature))];
        }
        $raw = match ($sigFlags) {
            VmPhar::SIG_MD5 => \md5($unsigned, true),
            VmPhar::SIG_SHA1 => \sha1($unsigned, true),
            VmPhar::SIG_SHA512 => \hash('sha512', $unsigned, true),
            default => \hash('sha256', $unsigned, true),
        };
        if (VmPhar::SIG_MD5 !== $sigFlags && VmPhar::SIG_SHA1 !== $sigFlags && VmPhar::SIG_SHA512 !== $sigFlags) {
            $sigFlags = VmPhar::SIG_SHA256;
        }

        return [$raw, $sigFlags, \strtoupper(\bin2hex($raw))];
    }

    /** @return array{sigFlags: int, signatureHex: string, dataEnd: int}|null */
    private static function parseSignatureFooter(string $binary): ?array
    {
        $len = \strlen($binary);
        if ($len < 8 || \substr($binary, -4) !== self::GBMB) {
            return null;
        }
        $sigFlags = self::readU32($binary, $len - 8);
        $digestLen = match ($sigFlags) {
            VmPhar::SIG_MD5 => 16,
            VmPhar::SIG_SHA1 => 20,
            VmPhar::SIG_SHA256 => 32,
            VmPhar::SIG_SHA512 => 64,
            VmPhar::SIG_OPENSSL, VmPhar::SIG_OPENSSL_SHA256, VmPhar::SIG_OPENSSL_SHA512 => null,
            default => null,
        };
        if (null === $digestLen) {
            if ($len < 12) {
                return null;
            }
            $opensslLen = self::readU32($binary, $len - 12);
            if ($opensslLen < 1 || $len < 12 + $opensslLen) {
                return null;
            }
            $raw = \substr($binary, $len - 12 - $opensslLen, $opensslLen);

            return [
                'sigFlags' => $sigFlags,
                'signatureHex' => \strtoupper(\bin2hex($raw)),
                'dataEnd' => $len - 12 - $opensslLen,
            ];
        }
        if ($len < 8 + $digestLen) {
            return null;
        }
        $raw = \substr($binary, $len - 8 - $digestLen, $digestLen);

        return [
            'sigFlags' => $sigFlags,
            'signatureHex' => \strtoupper(\bin2hex($raw)),
            'dataEnd' => $len - 8 - $digestLen,
        ];
    }

    private static function hasGbmbFooter(string $binary): bool
    {
        return \strlen($binary) >= 4 && \substr($binary, -4) === self::GBMB;
    }

    private static function isReserved(string $name): bool
    {
        return \str_starts_with($name, '.phar/');
    }

    private static function apiBytes(int $api): string
    {
        return \chr(($api >> 8) & 0xFF).\chr($api & 0xF0);
    }

    private static function u32(int $v): string
    {
        return \pack('V', $v & 0xFFFFFFFF);
    }

    private static function readU32(string $bin, int $off): int
    {
        $chunk = \substr($bin, $off, 4);
        if (4 !== \strlen($chunk)) {
            return 0;
        }
        $arr = \unpack('V', $chunk);

        return (int) ($arr[1] ?? 0);
    }

    private static function crc32u(string $data): int
    {
        return \crc32($data) & 0xFFFFFFFF;
    }
}
