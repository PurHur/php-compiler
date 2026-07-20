<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

/**
 * Phar path + static capability helpers — php-src ext/phar/phar_object.c / phar.c (#3436, #19871).
 */
final class VmPhar
{
    public const CLASS_LC = 'phar';

    /** php-src PHP_PHAR_API_VERSION (ext/phar/phar_internal.h). */
    public const API_VERSION = '1.1.1';

    /** php-src PHAR_ENT_COMPRESSED_* / Phar::NONE|GZ|BZ2. */
    public const COMPRESSED_NONE = 0;

    public const COMPRESSED_GZ = 0x00001000;

    public const COMPRESSED_BZ2 = 0x00002000;

    /** php-src PHAR_FORMAT_* / Phar::PHAR|TAR|ZIP (phar_internal.h). */
    public const FORMAT_PHAR = 1;

    public const FORMAT_TAR = 2;

    public const FORMAT_ZIP = 3;

    /** php-src PHAR_SIG_* (ext/phar/phar_internal.h). */
    public const SIG_MD5 = 0x0001;

    public const SIG_SHA1 = 0x0002;

    public const SIG_SHA256 = 0x0003;

    public const SIG_SHA512 = 0x0004;

    public const SIG_OPENSSL = 0x0010;

    public const SIG_OPENSSL_SHA256 = 0x0011;

    public const SIG_OPENSSL_SHA512 = 0x0012;

    /** Startup {@code -d phar.readonly=} override (bin/vm.php −d → VmIni::applyStartupIniOverride). */
    private static ?bool $startupReadonly = null;

    public static function setStartupReadonly(?bool $readonly): void
    {
        self::$startupReadonly = $readonly;
    }

    public static function startupReadonly(): ?bool
    {
        return self::$startupReadonly;
    }

    /**
     * Resolve archive path from SCRIPT_FILENAME / phar:// URI (#3436).
     */
    public static function runningPath(string $scriptPath, bool $retPhar): string
    {
        $pharPath = self::extractPharArchivePath($scriptPath);
        if (null === $pharPath) {
            return '';
        }
        if (!$retPhar) {
            return $pharPath;
        }

        $base = basename($pharPath);
        if (str_ends_with(strtolower($base), '.phar')) {
            return substr($base, 0, -5);
        }

        return $base;
    }

    /**
     * Phar::canWrite() — !PHAR_G(readonly); default phar.readonly=1 (phar.c PHP_INI).
     *
     * Honors {@code php bin/vm.php -d phar.readonly=0} via startup override (#20628), then host ini_get.
     */
    public static function canWrite(): bool
    {
        if (null !== self::$startupReadonly) {
            return !self::$startupReadonly;
        }
        $raw = \ini_get('phar.readonly');
        if (false === $raw || '' === $raw) {
            // Zend default when unset in this build is still readonly-on for CLI packages.
            return false;
        }

        return !self::iniBool($raw);
    }

    /**
     * Phar::canCompress($method = 0) — PHAR_G(has_zlib)/has_bz2 (phar_object.c).
     */
    /**
     * @return list<int>
     */
    public static function knownSignatureAlgorithms(): array
    {
        return [
            self::SIG_MD5,
            self::SIG_SHA1,
            self::SIG_SHA256,
            self::SIG_SHA512,
            self::SIG_OPENSSL,
            self::SIG_OPENSSL_SHA256,
            self::SIG_OPENSSL_SHA512,
        ];
    }

    public static function assertSignatureAlgorithm(int $algo): void
    {
        if (!\in_array($algo, self::knownSignatureAlgorithms(), true)) {
            throw new \UnexpectedValueException('Unknown signature algorithm specified');
        }
        if (\in_array($algo, [self::SIG_OPENSSL, self::SIG_OPENSSL_SHA256, self::SIG_OPENSSL_SHA512], true)
            && !\extension_loaded('openssl')) {
            throw new \UnexpectedValueException('Cannot set signature algorithm, OpenSSL extension is not available');
        }
    }

    /** @return string hex digest (uppercase) for hash-based signatures */
    public static function computeHashSignature(string $binary, int $algo): string
    {
        return match ($algo) {
            self::SIG_MD5 => \strtoupper(\md5($binary)),
            self::SIG_SHA1 => \strtoupper(\sha1($binary)),
            self::SIG_SHA256 => \strtoupper(\hash('sha256', $binary)),
            self::SIG_SHA512 => \strtoupper(\hash('sha512', $binary)),
            default => throw new \UnexpectedValueException('Unknown signature algorithm specified'),
        };
    }

    public static function signatureHashTypeName(int $algo): string
    {
        return match ($algo) {
            self::SIG_MD5 => 'MD5',
            self::SIG_SHA1 => 'SHA-1',
            self::SIG_SHA256 => 'SHA-256',
            self::SIG_SHA512 => 'SHA-512',
            self::SIG_OPENSSL => 'OpenSSL',
            self::SIG_OPENSSL_SHA256 => 'OpenSSL_SHA256',
            self::SIG_OPENSSL_SHA512 => 'OpenSSL_SHA512',
            default => 'Unknown ('.$algo.')',
        };
    }

    public static function canCompress(int $method = 0): bool
    {
        $hasZlib = \extension_loaded('zlib');
        $hasBz2 = \extension_loaded('bz2');
        switch ($method) {
            case self::COMPRESSED_GZ:
                return $hasZlib;
            case self::COMPRESSED_BZ2:
                return $hasBz2;
            default:
                return $hasZlib || $hasBz2;
        }
    }

    /**
     * Phar::isValidPharFilename() — phar_detect_phar_fname_ext(..., for_create=2).
     *
     * for_create=2 treats missing relative paths as valid when the extension rules pass
     * (parent cwd exists); we apply the extension rules only — php-src-strict for names.
     */
    public static function isValidPharFilename(string $filename, bool $executable = true): bool
    {
        $filenameLen = \strlen($filename);
        if ($filenameLen <= 1) {
            return false;
        }

        $slashPos = \strpos($filename, '/');
        if (false !== $slashPos && $slashPos > 0
            && ':' === $filename[$slashPos - 1]
            && $slashPos + 1 < $filenameLen
            && '/' === $filename[$slashPos + 1]
        ) {
            // URL schemes (http://, phar://, …) — not a plain filename.
            return false;
        }

        return self::detectPharFnameExt($filename, $executable ? 1 : 0);
    }

    private static function extractPharArchivePath(string $path): ?string
    {
        if ('' === $path) {
            return null;
        }
        if (str_starts_with($path, 'phar://')) {
            $rest = substr($path, 7);
            $pos = stripos($rest, '.phar');
            if (false === $pos) {
                return null;
            }

            return substr($rest, 0, $pos + 5);
        }
        $pos = stripos($path, '.phar');
        if (false === $pos) {
            return null;
        }

        return substr($path, 0, $pos + 5);
    }

    /** php.ini boolean truthy values (zend_ini.c). */
    private static function iniBool(string $raw): bool
    {
        $v = strtolower(trim($raw));

        return !('' === $v || '0' === $v || 'off' === $v || 'false' === $v || 'no' === $v);
    }

    /**
     * Subset of phar_detect_phar_fname_ext + phar_check_str (for_create ignored / always ok).
     */
    private static function detectPharFnameExt(string $filename, int $executable): bool
    {
        $filenameLen = \strlen($filename);
        $pos = \strpos($filename, '.', 1);
        while (false !== $pos) {
            while ($pos > 0 && ('/' === $filename[$pos - 1] || "\0" === $filename[$pos - 1])) {
                $pos = \strpos($filename, '.', $pos + 1);
                if (false === $pos) {
                    return false;
                }
            }

            $slashPos = \strpos($filename, '/', $pos);
            if (false === $slashPos) {
                $ext = substr($filename, $pos);
                $extLen = \strlen($ext);

                return self::checkStr($ext, $extLen, $executable);
            }

            $ext = substr($filename, $pos, $slashPos - $pos);
            $extLen = \strlen($ext);
            if (self::checkStr($ext, $extLen, $executable)) {
                return true;
            }
            $pos = \strpos($filename, '.', $pos + 1);
        }

        return false;
    }

    /** php-src phar_check_str — extension rules only. */
    private static function checkStr(string $extStr, int $extLen, int $executable): bool
    {
        if ($extLen >= 50) {
            return false;
        }

        if (1 === $executable) {
            $pos = \strpos($extStr, '.phar');
            if (false === $pos
                || ($pos > 0 && '/' === $extStr[$pos - 1])
                || ($extLen - $pos) < 5
            ) {
                return false;
            }
            $after = $pos + 5;
            if ($after < $extLen) {
                $ch = $extStr[$after];
                if ('/' !== $ch && '.' !== $ch) {
                    return false;
                }
            }

            return true;
        }

        if (0 === $executable) {
            $pos = \strpos($extStr, '.phar');
            $hasPhar = false !== $pos
                && (0 === $pos || '/' !== $extStr[$pos - 1])
                && ($pos + 5) <= $extLen
                && (
                    ($pos + 5) === $extLen
                    || '/' === $extStr[$pos + 5]
                    || '.' === $extStr[$pos + 5]
                );
            if (!$hasPhar && $extLen > 1
                && '.' !== $extStr[1]
                && '/' !== $extStr[1]
            ) {
                return true;
            }

            return false;
        }

        // executable == 2 (any) — rare for isValidPharFilename; treat like data/exec union.
        if ($extLen > 1 && '.' !== $extStr[1] && '/' !== $extStr[1]) {
            return true;
        }

        return false;
    }
}
