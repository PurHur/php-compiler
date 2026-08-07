<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

/**
 * MESSAGEPACK_OPT_* constants (PECL msgpack/msgpack-php msgpack_class.h; #27872).
 */
final class MsgpackConstants
{
    /** MSGPACK_CLASS_OPT_PHPONLY */
    public const MESSAGEPACK_OPT_PHPONLY = -1001;

    /** MSGPACK_CLASS_OPT_ASSOC */
    public const MESSAGEPACK_OPT_ASSOC = -1002;

    /** MSGPACK_CLASS_OPT_FORCE_F32 */
    public const MESSAGEPACK_OPT_FORCE_F32 = -1003;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'MESSAGEPACK_OPT_PHPONLY' => self::MESSAGEPACK_OPT_PHPONLY,
            'MESSAGEPACK_OPT_ASSOC' => self::MESSAGEPACK_OPT_ASSOC,
            'MESSAGEPACK_OPT_FORCE_F32' => self::MESSAGEPACK_OPT_FORCE_F32,
        ];
    }
}
