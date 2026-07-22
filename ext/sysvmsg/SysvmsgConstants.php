<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

/**
 * sysvmsg MSG_* constants (php-src ext/sysvmsg/sysvmsg.c; #22337).
 */
final class SysvmsgConstants
{
    /**
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        return [
            'MSG_IPC_NOWAIT' => \defined('MSG_IPC_NOWAIT') ? (int) \constant('MSG_IPC_NOWAIT') : 1,
            'MSG_EAGAIN' => \defined('MSG_EAGAIN') ? (int) \constant('MSG_EAGAIN') : 11,
            'MSG_ENOMSG' => \defined('MSG_ENOMSG') ? (int) \constant('MSG_ENOMSG') : 42,
            'MSG_NOERROR' => \defined('MSG_NOERROR') ? (int) \constant('MSG_NOERROR') : 2,
            'MSG_EXCEPT' => \defined('MSG_EXCEPT') ? (int) \constant('MSG_EXCEPT') : 4,
        ];
    }
}
