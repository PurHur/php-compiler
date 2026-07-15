<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

/**
 * Minimal ustar read/write for PharData (.tar) — php-src ext/phar/tar.c subset (#6490).
 *
 * PHP-in-PHP; no libarchive C runtime.
 */
final class VmPharTar
{
    /**
     * @return array<string, string> localname => contents
     */
    public static function readArchive(string $binary): array
    {
        $files = [];
        $len = \strlen($binary);
        $offset = 0;
        while ($offset + 512 <= $len) {
            $header = \substr($binary, $offset, 512);
            $offset += 512;
            if (self::isZeroBlock($header)) {
                break;
            }
            $name = self::headerName($header);
            $size = self::parseOctal(\substr($header, 124, 12));
            $typeflag = $header[156] ?? '0';
            $payload = $size > 0 ? \substr($binary, $offset, $size) : '';
            $offset += (int) (\ceil($size / 512) * 512);
            if ('' === $name) {
                continue;
            }
            // Regular file (or old POSIX '0'/'\0').
            if ('0' === $typeflag || "\0" === $typeflag || '' === $typeflag) {
                $files[$name] = $payload;
            }
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     */
    public static function writeArchive(array $files): string
    {
        $out = '';
        foreach ($files as $name => $contents) {
            $out .= self::buildHeader($name, $contents);
            $out .= $contents;
            $pad = (512 - (\strlen($contents) % 512)) % 512;
            if ($pad > 0) {
                $out .= \str_repeat("\0", $pad);
            }
        }
        $out .= \str_repeat("\0", 1024);

        return $out;
    }

    private static function isZeroBlock(string $header): bool
    {
        return '' === \trim($header, "\0");
    }

    private static function headerName(string $header): string
    {
        $prefix = \rtrim(\substr($header, 345, 155), "\0");
        $name = \rtrim(\substr($header, 0, 100), "\0");
        if ('' !== $prefix) {
            return $prefix.'/'.$name;
        }

        return $name;
    }

    private static function parseOctal(string $field): int
    {
        $field = \trim($field, "\0 ");
        if ('' === $field) {
            return 0;
        }

        return (int) \octdec($field);
    }

    private static function buildHeader(string $name, string $contents): string
    {
        $name = \str_replace('\\', '/', $name);
        $prefix = '';
        if (\strlen($name) > 100) {
            // Split at last slash within prefix/name limits when possible.
            $slash = \strrpos(\substr($name, 0, 155), '/');
            if (false !== $slash && \strlen($name) - $slash - 1 <= 100) {
                $prefix = \substr($name, 0, $slash);
                $name = \substr($name, $slash + 1);
            } else {
                $name = \substr($name, 0, 100);
            }
        }

        $header = \str_repeat("\0", 512);
        self::poke($header, 0, self::padName($name, 100));
        self::poke($header, 100, self::octal(0644, 8));
        self::poke($header, 108, self::octal(0, 8));
        self::poke($header, 116, self::octal(0, 8));
        self::poke($header, 124, self::octal(\strlen($contents), 12));
        self::poke($header, 136, self::octal(\time(), 12));
        self::poke($header, 148, '        '); // checksum blank
        $header[156] = '0';
        self::poke($header, 257, "ustar\0");
        self::poke($header, 263, '00');
        if ('' !== $prefix) {
            self::poke($header, 345, self::padName($prefix, 155));
        }

        $sum = 0;
        for ($i = 0; $i < 512; ++$i) {
            $sum += \ord($header[$i]);
        }
        self::poke($header, 148, self::octal($sum, 7)."\0");

        return $header;
    }

    private static function padName(string $name, int $len): string
    {
        if (\strlen($name) >= $len) {
            return \substr($name, 0, $len);
        }

        return $name.\str_repeat("\0", $len - \strlen($name));
    }

    private static function octal(int $value, int $width): string
    {
        $s = \sprintf('%0'.($width - 1).'o', $value);
        if (\strlen($s) > $width - 1) {
            $s = \substr($s, -($width - 1));
        }

        return $s."\0";
    }

    private static function poke(string &$buf, int $offset, string $value): void
    {
        $len = \strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $buf[$offset + $i] = $value[$i];
        }
    }
}
