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

    /**
     * php-src normalizer_get_raw_decomposition — UCD Decomposition_Mapping for one code point (#19535).
     *
     * @return string|null Decomposition mapping UTF-8, or null when absent / on intl error
     */
    public static function getRawDecomposition(
        string $input,
        int $form = self::FORM_C,
        string $function = 'normalizer_get_raw_decomposition'
    ): ?string {
        self::assertValidForm($function, 2, $form);
        IntlError::clear();
        $codepoints = UnicodeCanonical::utf8Codepoints($input);
        if (1 !== \count($codepoints)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                $function.': Input string must be exactly one UTF-8 encoded code point long.: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        $cp = $codepoints[0];
        if ($cp < 0 || $cp > 0x10FFFF) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                $function.': Code point out of range: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        $parts = UnicodeCanonical::decompose($cp);
        if (1 === \count($parts) && $parts[0] === $cp) {
            return null;
        }
        $out = '';
        foreach ($parts as $part) {
            $out .= UnicodeCanonical::codepointToUtf8($part);
        }

        return $out;
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
