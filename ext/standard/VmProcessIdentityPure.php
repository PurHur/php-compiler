<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process identity via /proc/self/status + /etc/passwd — no libc FFI (#9017, #12182).
 *
 * php-src: ext/standard/basic_functions.c — getmypid, getmyuid, getmygid, get_current_user
 * Linux procfs: man 5 proc — Pid, Uid, Gid fields in /proc/self/status
 */
final class VmProcessIdentityPure
{
    /** @var array{pid?:int,ppid?:int,uid?:int,gid?:int,euid?:int,egid?:int}|null */
    private static ?array $statusCache = null;

    /** @var list<int>|null|false */
    private static $groupsCache = null;

    public static function available(): bool
    {
        return null !== self::loadStatus();
    }

    public static function getpid(): ?int
    {
        $status = self::loadStatus();

        return $status['pid'] ?? null;
    }

    public static function getuid(): ?int
    {
        $status = self::loadStatus();

        return $status['uid'] ?? null;
    }

    public static function getgid(): ?int
    {
        $status = self::loadStatus();

        return $status['gid'] ?? null;
    }

    public static function geteuid(): ?int
    {
        $status = self::loadStatus();

        return $status['euid'] ?? null;
    }

    public static function getegid(): ?int
    {
        $status = self::loadStatus();

        return $status['egid'] ?? null;
    }

    public static function getppid(): ?int
    {
        $status = self::loadStatus();

        return $status['ppid'] ?? null;
    }

    /**
     * Supplementary group IDs — Linux /proc/self/groups (#12394).
     *
     * @return list<int>|null
     */
    public static function getgroups(): ?array
    {
        if (false === self::$groupsCache) {
            return null;
        }
        if (null !== self::$groupsCache) {
            return self::$groupsCache;
        }

        self::$groupsCache = false;
        if ('Linux' !== \PHP_OS_FAMILY) {
            return null;
        }

        $raw = self::readProcGroups();
        if (false === $raw) {
            $raw = self::readGroupsFromStatus();
            if (null === $raw) {
                return null;
            }
        }

        $groups = [];
        foreach (\preg_split('/\s+/', \trim($raw)) ?: [] as $token) {
            if ('' === $token || !\preg_match('/^\d+$/', $token)) {
                continue;
            }
            $groups[] = (int) $token;
        }

        self::$groupsCache = $groups;

        return self::$groupsCache;
    }

    public static function getpwuidName(int $uid): ?string
    {
        $raw = self::readPasswd();
        if (false === $raw) {
            return null;
        }

        foreach (explode("\n", $raw) as $line) {
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = explode(':', $line);
            if (\count($parts) < 3) {
                continue;
            }
            if ((int) $parts[2] === $uid) {
                $name = $parts[0];
                if ('' !== $name) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @return array{pid:int,ppid:int,uid:int,gid:int,euid:int,egid:int}|null
     */
    private static function loadStatus(): ?array
    {
        if (null !== self::$statusCache) {
            return self::$statusCache ?: null;
        }

        self::$statusCache = [];
        if ('Linux' !== \PHP_OS_FAMILY) {
            return null;
        }

        $raw = self::readProcStatus();
        if (false === $raw) {
            return null;
        }

        $pid = self::parseIntField($raw, 'Pid');
        $ppid = self::parseIntField($raw, 'PPid');
        $uidLine = self::parseField($raw, 'Uid');
        $gidLine = self::parseField($raw, 'Gid');
        if (null === $pid || null === $ppid || null === $uidLine || null === $gidLine) {
            return null;
        }

        $uids = preg_split('/\s+/', trim($uidLine));
        $gids = preg_split('/\s+/', trim($gidLine));
        if (!\is_array($uids) || !\is_array($gids) || [] === $uids || [] === $gids) {
            return null;
        }

        self::$statusCache = [
            'pid' => $pid,
            'ppid' => $ppid,
            'uid' => (int) $uids[0],
            'euid' => (int) ($uids[1] ?? $uids[0]),
            'gid' => (int) $gids[0],
            'egid' => (int) ($gids[1] ?? $gids[0]),
        ];

        return self::$statusCache;
    }

    private static function readProcGroups(): string|false
    {
        if (!\is_readable('/proc/self/groups')) {
            return false;
        }
        if (VmFsReadNative::available()) {
            $raw = VmFsReadNative::read('/proc/self/groups');
            if (false !== $raw && '' !== $raw) {
                return $raw;
            }
        }

        $raw = @\file_get_contents('/proc/self/groups');

        return \is_string($raw) && '' !== $raw ? $raw : false;
    }

    private static function readGroupsFromStatus(): ?string
    {
        $raw = self::readProcStatus();
        if (false === $raw) {
            return null;
        }

        return self::parseField($raw, 'Groups');
    }

    private static function readProcStatus(): string|false
    {
        if (!\is_readable('/proc/self/status')) {
            return false;
        }
        if (VmFsReadNative::available()) {
            $raw = VmFsReadNative::read('/proc/self/status');
            if (false !== $raw && '' !== $raw) {
                return $raw;
            }
        }

        $raw = @\file_get_contents('/proc/self/status');

        return \is_string($raw) && '' !== $raw ? $raw : false;
    }

    private static function readPasswd(): string|false
    {
        if (!\is_readable('/etc/passwd')) {
            return false;
        }
        if (VmFsReadNative::available()) {
            $raw = VmFsReadNative::read('/etc/passwd');
            if (false !== $raw && '' !== $raw) {
                return $raw;
            }
        }

        $raw = @\file_get_contents('/etc/passwd');

        return \is_string($raw) && '' !== $raw ? $raw : false;
    }

    private static function parseField(string $raw, string $key): ?string
    {
        $pattern = '/^'.preg_quote($key, '/').':\s+(.+)$/m';
        if (!preg_match($pattern, $raw, $m)) {
            return null;
        }

        return trim($m[1]);
    }

    private static function parseIntField(string $raw, string $key): ?int
    {
        $field = self::parseField($raw, $key);
        if (null === $field || !preg_match('/^\d+$/', $field)) {
            return null;
        }

        return (int) $field;
    }
}
