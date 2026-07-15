<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmStreamArg;

/**
 * Unicode normalization (php-src ext/intl/normalizer/normalizer_normalize.c; issue #5153).
 *
 * v1: PHP tables in {@see UnicodeCanonical}; NFKC/NFKD alias canonical forms for BMP Latin-1.
 */
final class VmNormalizer
{
    public const FORM_D = 4;

    public const FORM_KD = 8;

    public const FORM_C = 16;

    public const FORM_KC = 32;

    /** @return list<int> */
    public static function validForms(): array
    {
        return [self::FORM_D, self::FORM_KD, self::FORM_C, self::FORM_KC];
    }

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'FORM_D' => self::FORM_D,
            'FORM_KD' => self::FORM_KD,
            'FORM_C' => self::FORM_C,
            'FORM_KC' => self::FORM_KC,
        ];
    }

    public static function normalize(string $input, int $form = self::FORM_C): string
    {
        self::assertValidForm('normalizer_normalize', 2, $form);

        return match ($form) {
            self::FORM_D, self::FORM_KD => UnicodeCanonical::normalizeNfd($input),
            self::FORM_C, self::FORM_KC => UnicodeCanonical::normalizeNfc($input),
        };
    }

    public static function isNormalized(string $input, int $form = self::FORM_C): bool
    {
        self::assertValidForm('normalizer_is_normalized', 2, $form);

        return match ($form) {
            self::FORM_D, self::FORM_KD => UnicodeCanonical::isNormalizedNfd($input),
            self::FORM_C, self::FORM_KC => UnicodeCanonical::isNormalizedNfc($input),
        };
    }

    public static function parseFormFromFrame(
        \PHPCompiler\Frame $frame,
        int $argIndex,
        string $function,
        int $position
    ): int {
        if (!isset($frame->calledArgs[$argIndex])) {
            return self::FORM_C;
        }
        $resolved = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_NULL === $resolved->type) {
            return self::FORM_C;
        }
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($form) must be of type int, %s given',
                $function,
                $position,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
        $form = $resolved->toInt();
        self::assertValidForm($function, $position, $form);

        return $form;
    }

    private static function assertValidForm(string $function, int $position, int $form): void
    {
        if (\in_array($form, self::validForms(), true)) {
            return;
        }
        throw new \ValueError(\sprintf(
            '%s(): Argument #%d ($form) must be one of Normalizer::FORM_* constants',
            $function,
            $position
        ));
    }
}
