<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\ext\standard\VmFsReadNative;

/**
 * Pure PHP GNU MO catalog reader for gettext builtins (#8952, php-src ext/gettext/gettext.c).
 *
 * Replaces libintl FFI in {@see VmGettextNative} for self-host / bootstrap paths.
 */
final class VmGettextPure
{
    private const MO_MAGIC_LE = 0x950412DE;

    private const MO_MAGIC_BE = 0xDE120495;

    private const LC_MESSAGES = 5;

    /** @var array<string, array<string, string>> */
    private static array $catalogCache = [];

    public static function available(): bool
    {
        return true;
    }

    public static function translate(
        string $domain,
        string $msgid,
        int $category = self::LC_MESSAGES
    ): string {
        $catalog = self::catalogForDomain($domain, $category);
        if (null === $catalog) {
            return $msgid;
        }

        return $catalog[$msgid] ?? $msgid;
    }

    public static function translatePlural(
        string $domain,
        string $msgid1,
        string $msgid2,
        int $count,
        int $category = self::LC_MESSAGES
    ): string {
        $catalog = self::catalogForDomain($domain, $category);
        if (null === $catalog) {
            return 1 === $count ? $msgid1 : $msgid2;
        }

        $key = $msgid1."\0".$msgid2;
        if (!isset($catalog[$key])) {
            return 1 === $count ? $msgid1 : $msgid2;
        }

        $forms = explode("\0", $catalog[$key]);
        if (1 === $count) {
            return $forms[0] ?? $msgid1;
        }

        return $forms[1] ?? $forms[0] ?? $msgid2;
    }

    /**
     * @return array<string, string>|null
     */
    public static function catalogForDomain(string $domain, int $category = self::LC_MESSAGES): ?array
    {
        $path = VmGettextNative::boundDirectory($domain);
        if ('' === $path) {
            return null;
        }

        $moPath = self::resolveMoPath($path, $domain, $category);
        if (null === $moPath) {
            return null;
        }

        if (isset(self::$catalogCache[$moPath])) {
            return self::$catalogCache[$moPath];
        }

        $bytes = VmFsReadNative::read($moPath);
        if (!\is_string($bytes) || '' === $bytes) {
            return null;
        }

        $catalog = self::parseMo($bytes);
        if (null === $catalog) {
            return null;
        }

        self::$catalogCache[$moPath] = $catalog;

        return $catalog;
    }

    public static function resolveMoPath(string $directory, string $domain, int $category): ?string
    {
        $locale = self::localeForCategory($category);
        $candidates = [
            $directory.'/'.$locale.'/LC_MESSAGES/'.$domain.'.mo',
            $directory.'/LC_MESSAGES/'.$domain.'.mo',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function localeForCategory(int $category): string
    {
        unset($category);
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $name) {
            $value = getenv($name);
            if (\is_string($value) && '' !== $value) {
                return self::normalizeLocale($value);
            }
        }

        return 'C';
    }

    private static function normalizeLocale(string $locale): string
    {
        if ('0' === $locale) {
            return 'C';
        }
        $semi = strpos($locale, ';');
        if (false !== $semi) {
            $locale = substr($locale, 0, $semi);
        }
        $dot = strpos($locale, '.');
        if (false !== $dot) {
            return substr($locale, 0, $dot).substr($locale, $dot);
        }

        return $locale;
    }

    /**
     * @return array<string, string>|null
     */
    public static function parseMo(string $bytes): ?array
    {
        if (8 > \strlen($bytes)) {
            return null;
        }

        $magic = unpack('V', substr($bytes, 0, 4))[1];
        if (self::MO_MAGIC_LE === $magic) {
            $little = true;
        } elseif (self::MO_MAGIC_BE === $magic) {
            $little = false;
        } else {
            return null;
        }

        $readLong = static function (string $chunk) use ($little): int {
            $format = $little ? 'V' : 'N';

            return (int) unpack($format, $chunk)[1];
        };

        $revision = $readLong(substr($bytes, 4, 4));
        if (0 !== $revision) {
            return null;
        }

        $count = $readLong(substr($bytes, 8, 4));
        $origTableOffset = $readLong(substr($bytes, 12, 4));
        $transTableOffset = $readLong(substr($bytes, 16, 4));
        if ($count < 0 || $origTableOffset < 28 || $transTableOffset < 28) {
            return null;
        }

        $catalog = [];
        for ($i = 0; $i < $count; ++$i) {
            $origMetaOffset = $origTableOffset + ($i * 8);
            $transMetaOffset = $transTableOffset + ($i * 8);
            if ($origMetaOffset + 8 > \strlen($bytes) || $transMetaOffset + 8 > \strlen($bytes)) {
                return null;
            }

            $origLength = $readLong(substr($bytes, $origMetaOffset, 4));
            $origOffset = $readLong(substr($bytes, $origMetaOffset + 4, 4));
            $transLength = $readLong(substr($bytes, $transMetaOffset, 4));
            $transOffset = $readLong(substr($bytes, $transMetaOffset + 4, 4));

            if ($origOffset + $origLength > \strlen($bytes) || $transOffset + $transLength > \strlen($bytes)) {
                return null;
            }

            $orig = substr($bytes, $origOffset, $origLength);
            $trans = substr($bytes, $transOffset, $transLength);
            $catalog[$orig] = $trans;
        }

        return $catalog;
    }

    /** @internal test helper */
    public static function buildMoFile(array $pairs): string
    {
        $count = \count($pairs);
        $headerSize = 28;
        $indexSize = $count * 8;
        $origIndexOffset = $headerSize;
        $transIndexOffset = $origIndexOffset + $indexSize;
        $dataOffset = $transIndexOffset + $indexSize;

        $origIndex = '';
        $transIndex = '';
        $data = '';
        $cursor = $dataOffset;

        foreach ($pairs as $orig => $trans) {
            $origBytes = (string) $orig;
            $transBytes = (string) $trans;
            $origIndex .= pack('VV', \strlen($origBytes), $cursor);
            $data .= $origBytes;
            $cursor += \strlen($origBytes);
            $transIndex .= pack('VV', \strlen($transBytes), $cursor);
            $data .= $transBytes;
            $cursor += \strlen($transBytes);
        }

        $header = pack('V', self::MO_MAGIC_LE);
        $header .= pack('V', 0);
        $header .= pack('V', $count);
        $header .= pack('V', $origIndexOffset);
        $header .= pack('V', $transIndexOffset);
        $header .= pack('V', 0);
        $header .= pack('V', 0);

        return $header.$origIndex.$transIndex.$data;
    }
}
