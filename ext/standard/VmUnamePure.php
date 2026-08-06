<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * php_uname() without libc uname(2) FFI — /proc, /etc/os-release, PHP_OS (#8904, #1492).
 *
 * php-src: ext/standard/info.c — php_get_uname / PHP_FUNCTION(php_uname)
 *
 * PROFILE≥8.4 ValueError is {@see assertValidMode()} — callers gate it so NestedJIT AOT
 * never calls raw getenv() from this helper (#21888 / #28136).
 */
final class VmUnamePure
{
    /** Valid single-letter modes (php_is_valid_uname_mode, ext/standard/info.c). */
    private const VALID_MODES = 'amnrsv';

    /** @var array{sysname: string, nodename: string, release: string, version: string, machine: string, domainname: string}|null */
    private static ?array $cached = null;

    public static function available(): bool
    {
        return '' !== self::utsname()['sysname'];
    }

    /**
     * Host/compile-time PROFILE≥8.4 gate. Do not call from NestedJIT AOT helpers (raw getenv SEGVs).
     */
    public static function requiresStrictModeValidation(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * Host compile-time fold gate (#28136). Soft profile may fold any single char (incl. unknown).
     */
    public static function canFoldMode(string $mode): bool
    {
        if (1 !== \strlen($mode)) {
            return false;
        }
        if (self::requiresStrictModeValidation()) {
            return false !== \strpos(self::VALID_MODES, $mode);
        }

        return true;
    }

    /**
     * Zend 8.4 mode checks (ext/standard/info.c). No getenv — NestedJIT-safe (#28136).
     *
     * @throws \ValueError
     */
    public static function assertValidMode(string $mode): void
    {
        $message = self::invalidModeValueErrorMessage($mode);
        if (null !== $message) {
            throw new \ValueError($message);
        }
    }

    /** @return non-empty-string|null ValueError message, or null when $mode is valid */
    public static function invalidModeValueErrorMessage(string $mode): ?string
    {
        if (1 !== \strlen($mode)) {
            return 'php_uname(): Argument #1 ($mode) must be a single character';
        }
        if (false === \strpos(self::VALID_MODES, $mode)) {
            return 'php_uname(): Argument #1 ($mode) must be one of "a", "m", "n", "r", "s", or "v"';
        }

        return null;
    }

    public static function php_uname(string $mode = 'a'): string
    {
        $uts = self::utsname();

        return match ($mode[0] ?? 'a') {
            's' => $uts['sysname'],
            'n' => $uts['nodename'],
            'r' => $uts['release'],
            'v' => $uts['version'],
            'm' => $uts['machine'],
            default => \sprintf(
                '%s %s %s %s %s',
                $uts['sysname'],
                $uts['nodename'],
                $uts['release'],
                $uts['version'],
                $uts['machine']
            ),
        };
    }

    /**
     * @return array{sysname: string, nodename: string, release: string, version: string, machine: string, domainname: string}
     */
    public static function utsname(): array
    {
        if (null !== self::$cached) {
            return self::$cached;
        }

        self::$cached = self::probeUtsname();

        return self::$cached;
    }

    /**
     * @return array{sysname: string, nodename: string, release: string, version: string, machine: string, domainname: string}
     */
    private static function probeUtsname(): array
    {
        if ('Linux' === \PHP_OS_FAMILY) {
            return self::probeLinux();
        }
        if ('Darwin' === \PHP_OS_FAMILY) {
            return self::probeDarwin();
        }
        if ('Windows' === \PHP_OS_FAMILY) {
            return self::probeWindows();
        }

        return self::probeGeneric();
    }

    /**
     * @return array{sysname: string, nodename: string, release: string, version: string, machine: string, domainname: string}
     */
    private static function probeLinux(): array
    {
        $procVersion = self::readText('/proc/version');
        $release = self::readTrimmed('/proc/sys/kernel/osrelease');
        $version = '';
        $machine = '';
        if (null !== $procVersion) {
            if (preg_match('/(#[^\n]+)$/', $procVersion, $matches)) {
                $version = \trim($matches[1]);
            }
            if (preg_match('/\(([^-]+)-linux-gnu-gcc/', $procVersion, $matches)) {
                $machine = $matches[1];
            }
        }

        return [
            'sysname' => self::linuxSysname(),
            'nodename' => self::readHostname(),
            'release' => $release ?? '',
            'version' => $version,
            'machine' => $machine,
            'domainname' => self::readDomainname(),
        ];
    }

    /**
     * @return array{sysname: string, nodename: string, release: string, version: string, machine: string, domainname: string}
     */
    private static function probeDarwin(): array
    {
        $sysname = 'Darwin';
        $release = self::readTrimmed('/System/Library/CoreServices/SystemVersion.plist');
        if (null !== $release && preg_match('/<key>ProductVersion<\/key>\s*<string>([^<]+)<\/string>/', $release, $matches)) {
            $release = $matches[1];
        } else {
            $release = '';
        }

        return [
            'sysname' => $sysname,
            'nodename' => self::readHostname(),
            'release' => $release,
            'version' => '',
            'machine' => self::darwinMachine(),
            'domainname' => self::readDomainname(),
        ];
    }

    /**
     * @return array{sysname: string, nodename: string, release: string, version: string, machine: string, domainname: string}
     */
    private static function probeWindows(): array
    {
        return [
            'sysname' => 'Windows NT',
            'nodename' => self::readHostname(),
            'release' => '',
            'version' => '',
            'machine' => '',
            'domainname' => '',
        ];
    }

    /**
     * @return array{sysname: string, nodename: string, release: string, version: string, machine: string, domainname: string}
     */
    private static function probeGeneric(): array
    {
        $sysname = \defined('PHP_OS') ? (string) \PHP_OS : '';

        return [
            'sysname' => $sysname,
            'nodename' => self::readHostname(),
            'release' => '',
            'version' => '',
            'machine' => '',
            'domainname' => self::readDomainname(),
        ];
    }

    private static function linuxSysname(): string
    {
        return 'Linux';
    }

    private static function darwinMachine(): string
    {
        if (\defined('PHP_OS') && \str_contains((string) \PHP_OS, 'arm')) {
            return 'arm64';
        }

        return 'x86_64';
    }

    private static function readHostname(): string
    {
        $fromEtc = self::readTrimmed('/etc/hostname');
        if (null !== $fromEtc && '' !== $fromEtc) {
            return $fromEtc;
        }
        $env = \getenv('HOSTNAME');
        if (false !== $env && '' !== $env) {
            return $env;
        }
        if (\function_exists('gethostname')) {
            $host = \gethostname();
            if (false !== $host && '' !== $host) {
                return $host;
            }
        }

        return '';
    }

    /** php-src ext/posix/posix.c — utsname.domainname; Linux default "(none)". */
    private static function readDomainname(): string
    {
        if ('Linux' === \PHP_OS_FAMILY) {
            $fromProc = self::readTrimmed('/proc/sys/kernel/domainname');
            if (null !== $fromProc && '' !== $fromProc) {
                return $fromProc;
            }

            return '(none)';
        }

        return '';
    }

    private static function readTrimmed(string $path): ?string
    {
        $raw = self::readText($path);
        if (null === $raw) {
            return null;
        }
        $trimmed = \trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }

    private static function readText(string $path): ?string
    {
        if (\str_contains($path, "\0") || !\is_readable($path)) {
            return null;
        }

        $viaVmFs = VmFsReadNative::read($path);
        if (false !== $viaVmFs && '' !== $viaVmFs) {
            return $viaVmFs;
        }

        $content = @\file_get_contents($path);
        if (false === $content) {
            return null;
        }

        return $content;
    }
}
