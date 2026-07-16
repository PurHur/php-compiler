<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ext\standard\VmFsReadNative;

/**
 * posix_getpwuid()/getpwnam()/getgrgid()/getgrnam() via /etc/passwd + /etc/group (#6489).
 *
 * No libc getpwuid/getgrgid FFI — PHP-in-PHP SSOT matching {@see \PHPCompiler\ext\standard\VmProcessIdentityPure}.
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getpwuid), posix_getpwnam, posix_getgrgid, posix_getgrnam
 */
final class VmPosixPasswdPure
{
    /**
     * @return array{name:string,passwd:string,uid:int,gid:int,gecos:string,dir:string,shell:string}|null
     */
    public static function getpwuid(int $uid): ?array
    {
        $raw = self::readEtcFile('/etc/passwd');
        if (false === $raw) {
            return null;
        }

        foreach (explode("\n", $raw) as $line) {
            $entry = self::parsePasswdLine($line);
            if (null !== $entry && $entry['uid'] === $uid) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{name:string,passwd:string,uid:int,gid:int,gecos:string,dir:string,shell:string}|null
     */
    public static function getpwnam(string $name): ?array
    {
        if ('' === $name) {
            return null;
        }
        $raw = self::readEtcFile('/etc/passwd');
        if (false === $raw) {
            return null;
        }

        foreach (explode("\n", $raw) as $line) {
            $entry = self::parsePasswdLine($line);
            if (null !== $entry && $entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{name:string,passwd:string,members:list<string>,gid:int}|null
     */
    public static function getgrgid(int $gid): ?array
    {
        $raw = self::readEtcFile('/etc/group');
        if (false === $raw) {
            return null;
        }

        foreach (explode("\n", $raw) as $line) {
            $entry = self::parseGroupLine($line);
            if (null !== $entry && $entry['gid'] === $gid) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{name:string,passwd:string,members:list<string>,gid:int}|null
     */
    public static function getgrnam(string $name): ?array
    {
        if ('' === $name) {
            return null;
        }
        $raw = self::readEtcFile('/etc/group');
        if (false === $raw) {
            return null;
        }

        foreach (explode("\n", $raw) as $line) {
            $entry = self::parseGroupLine($line);
            if (null !== $entry && $entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{name:string,passwd:string,uid:int,gid:int,gecos:string,dir:string,shell:string}|null
     */
    private static function parsePasswdLine(string $line): ?array
    {
        if ('' === $line || '#' === $line[0]) {
            return null;
        }
        $parts = explode(':', $line);
        if (\count($parts) < 7) {
            return null;
        }

        return [
            'name' => $parts[0],
            'passwd' => $parts[1],
            'uid' => (int) $parts[2],
            'gid' => (int) $parts[3],
            'gecos' => $parts[4],
            'dir' => $parts[5],
            'shell' => $parts[6],
        ];
    }

    /**
     * @return array{name:string,passwd:string,members:list<string>,gid:int}|null
     */
    private static function parseGroupLine(string $line): ?array
    {
        if ('' === $line || '#' === $line[0]) {
            return null;
        }
        $parts = explode(':', $line, 4);
        if (\count($parts) < 3) {
            return null;
        }
        $membersRaw = $parts[3] ?? '';
        $members = [];
        if ('' !== $membersRaw) {
            foreach (explode(',', $membersRaw) as $m) {
                if ('' !== $m) {
                    $members[] = $m;
                }
            }
        }

        return [
            'name' => $parts[0],
            'passwd' => $parts[1],
            'members' => $members,
            'gid' => (int) $parts[2],
        ];
    }

    private static function readEtcFile(string $path): string|false
    {
        if (!\is_readable($path)) {
            return false;
        }
        if (VmFsReadNative::available()) {
            $raw = VmFsReadNative::read($path);
            if (false !== $raw && '' !== $raw) {
                return $raw;
            }
        }

        $raw = @\file_get_contents($path);

        return \is_string($raw) && '' !== $raw ? $raw : false;
    }
}
