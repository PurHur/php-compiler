<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

/**
 * PSPELL_* mode constants (php-src ext/pspell/pspell.c; #6294).
 */
final class PspellConstants
{
    /** php-src PSPELL_FAST */
    public const PSPELL_FAST = 1;

    /** php-src PSPELL_NORMAL */
    public const PSPELL_NORMAL = 2;

    /** php-src PSPELL_BAD_SPELLERS */
    public const PSPELL_BAD_SPELLERS = 3;

    /** php-src PSPELL_RUN_TOGETHER */
    public const PSPELL_RUN_TOGETHER = 8;

    /** php-src PSPELL_SPEED_MASK_INTERNAL — low bits select sug-mode. */
    public const SPEED_MASK = 3;

    /**
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        return [
            'PSPELL_FAST' => self::PSPELL_FAST,
            'PSPELL_NORMAL' => self::PSPELL_NORMAL,
            'PSPELL_BAD_SPELLERS' => self::PSPELL_BAD_SPELLERS,
            'PSPELL_RUN_TOGETHER' => self::PSPELL_RUN_TOGETHER,
        ];
    }
}
