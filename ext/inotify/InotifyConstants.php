<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

/**
 * IN_* constants (php-src ext/inotify/php_inotify.h, Linux sys/inotify.h).
 */
final class InotifyConstants
{
    public const IN_ACCESS = 0x00000001;

    public const IN_MODIFY = 0x00000002;

    public const IN_ATTRIB = 0x00000004;

    public const IN_CLOSE_WRITE = 0x00000008;

    public const IN_CLOSE_NOWRITE = 0x00000010;

    public const IN_OPEN = 0x00000020;

    public const IN_MOVED_FROM = 0x00000040;

    public const IN_MOVED_TO = 0x00000080;

    public const IN_CREATE = 0x00000100;

    public const IN_DELETE = 0x00000200;

    public const IN_DELETE_SELF = 0x00000400;

    public const IN_MOVE_SELF = 0x00000800;

    public const IN_UNMOUNT = 0x00002000;

    public const IN_Q_OVERFLOW = 0x00004000;

    public const IN_IGNORED = 0x00008000;

    public const IN_CLOSE = self::IN_CLOSE_WRITE | self::IN_CLOSE_NOWRITE;

    public const IN_MOVE = self::IN_MOVED_FROM | self::IN_MOVED_TO;

    public const IN_ALL_EVENTS = self::IN_ACCESS | self::IN_MODIFY | self::IN_ATTRIB
        | self::IN_CLOSE_WRITE | self::IN_CLOSE_NOWRITE | self::IN_OPEN
        | self::IN_MOVED_FROM | self::IN_MOVED_TO | self::IN_CREATE | self::IN_DELETE
        | self::IN_DELETE_SELF | self::IN_MOVE_SELF | self::IN_UNMOUNT;

    public const IN_DONT_FOLLOW = 0x00200000;

    public const IN_EXCL_UNLINK = 0x04000000;

    public const IN_MASK_ADD = 0x20000000;

    public const IN_ISDIR = 0x40000000;

    public const IN_ONESHOT = 0x80000000;

    public const IN_ONLYDIR = 0x01000000;

    /**
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        return [
            'IN_ACCESS' => self::IN_ACCESS,
            'IN_MODIFY' => self::IN_MODIFY,
            'IN_ATTRIB' => self::IN_ATTRIB,
            'IN_CLOSE_WRITE' => self::IN_CLOSE_WRITE,
            'IN_CLOSE_NOWRITE' => self::IN_CLOSE_NOWRITE,
            'IN_OPEN' => self::IN_OPEN,
            'IN_MOVED_FROM' => self::IN_MOVED_FROM,
            'IN_MOVED_TO' => self::IN_MOVED_TO,
            'IN_CREATE' => self::IN_CREATE,
            'IN_DELETE' => self::IN_DELETE,
            'IN_DELETE_SELF' => self::IN_DELETE_SELF,
            'IN_MOVE_SELF' => self::IN_MOVE_SELF,
            'IN_UNMOUNT' => self::IN_UNMOUNT,
            'IN_Q_OVERFLOW' => self::IN_Q_OVERFLOW,
            'IN_IGNORED' => self::IN_IGNORED,
            'IN_CLOSE' => self::IN_CLOSE,
            'IN_MOVE' => self::IN_MOVE,
            'IN_ALL_EVENTS' => self::IN_ALL_EVENTS,
            'IN_DONT_FOLLOW' => self::IN_DONT_FOLLOW,
            'IN_EXCL_UNLINK' => self::IN_EXCL_UNLINK,
            'IN_MASK_ADD' => self::IN_MASK_ADD,
            'IN_ISDIR' => self::IN_ISDIR,
            'IN_ONESHOT' => self::IN_ONESHOT,
            'IN_ONLYDIR' => self::IN_ONLYDIR,
        ];
    }
}
