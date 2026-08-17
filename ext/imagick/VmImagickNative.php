<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

/**
 * ImageMagick CLI bridge — thin proc_open layer for PHP-in-PHP Imagick (#6235).
 *
 * php-src reference: ext/imagick/imagick_class.c (MagickWand); CLI used when pecl-imagick absent.
 */
final class VmImagickNative
{
    private static ?bool $cliAvailable = null;

    public static function cliAvailable(): bool
    {
        if (null !== self::$cliAvailable) {
            return self::$cliAvailable;
        }

        foreach (['magick', 'convert', 'identify'] as $cmd) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null'));
            if ('' !== $path && is_executable($path)) {
                return self::$cliAvailable = true;
            }
        }

        return self::$cliAvailable = false;
    }

    /**
     * @return array{width: int, height: int}|false
     */
    public static function identifyDimensions(string $path): array|false
    {
        if (!is_file($path)) {
            return false;
        }

        $out = self::runIdentify($path);
        if (null === $out) {
            return false;
        }
        $parts = preg_split('/\s+/', trim($out));
        if (!\is_array($parts) || 2 !== \count($parts)) {
            return false;
        }
        $w = (int) $parts[0];
        $h = (int) $parts[1];
        if ($w <= 0 || $h <= 0) {
            return false;
        }

        return ['width' => $w, 'height' => $h];
    }

    private static function runIdentify(string $path): ?string
    {
        $identify = trim((string) shell_exec('command -v identify 2>/dev/null'));
        if ('' !== $identify && is_executable($identify)) {
            $cmd = escapeshellcmd($identify).' -format '.escapeshellarg('%w %h').' '.escapeshellarg($path);

            return self::runCommand($cmd);
        }

        $magick = trim((string) shell_exec('command -v magick 2>/dev/null'));
        if ('' !== $magick && is_executable($magick)) {
            $cmd = escapeshellcmd($magick).' identify -format '.escapeshellarg('%w %h').' '.escapeshellarg($path);

            return self::runCommand($cmd);
        }

        $convert = trim((string) shell_exec('command -v convert 2>/dev/null'));
        if ('' === $convert || !is_executable($convert)) {
            return null;
        }
        $cmd = escapeshellcmd($convert).' '.escapeshellarg($path).' -format '.escapeshellarg('%w %h').' info:';

        return self::runCommand($cmd);
    }

    public static function resizeFile(string $src, string $dst, int $width, int $height, int $filter, float $blur, bool $bestfit): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $geometry = $bestfit
            ? $width.'x'.$height
            : $width.'x'.$height.'!';
        $middle = [
            '-filter',
            self::filterName($filter),
            '-resize',
            $geometry,
        ];
        if ($blur > 0.0 && 1.0 !== $blur) {
            $middle[] = '-blur';
            $middle[] = '0x'.(string) $blur;
        }

        return null !== self::runCommand(self::convertCommand($src, $middle, $dst));
    }

    public static function copyFile(string $src, string $dst): bool
    {
        if (!is_file($src)) {
            return false;
        }

        return @copy($src, $dst);
    }

    /** @param list<string> $middle operands between input and output paths */
    private static function convertCommand(string $src, array $middle, string $dst): string
    {
        $magick = trim((string) shell_exec('command -v magick 2>/dev/null'));
        if ('' !== $magick && is_executable($magick)) {
            return escapeshellcmd($magick).' convert '
                .escapeshellarg($src).' '
                .implode(' ', array_map('escapeshellarg', $middle)).' '
                .escapeshellarg($dst);
        }
        $convert = trim((string) shell_exec('command -v convert 2>/dev/null'));
        if ('' === $convert || !is_executable($convert)) {
            return '';
        }

        return escapeshellcmd($convert).' '
            .escapeshellarg($src).' '
            .implode(' ', array_map('escapeshellarg', $middle)).' '
            .escapeshellarg($dst);
    }

    private static function filterName(int $filter): string
    {
        return match ($filter) {
            1 => 'Point',
            2 => 'Box',
            3 => 'Triangle',
            4 => 'Hermite',
            5 => 'Hanning',
            6 => 'Hamming',
            7 => 'Blackman',
            8 => 'Gaussian',
            9 => 'Quadratic',
            10 => 'Cubic',
            11 => 'Catrom',
            12 => 'Mitchell',
            13 => 'Jinc',
            14 => 'Sinc',
            15 => 'SincFast',
            16 => 'Kaiser',
            17 => 'Welch',
            18 => 'Parzen',
            19 => 'Bohem',
            20 => 'Bohman',
            21 => 'Bartlett',
            22 => 'Lagrange',
            23 => 'Lanczos',
            24 => 'LanczosSharp',
            25 => 'Lanczos2',
            26 => 'Lanczos2Sharp',
            27 => 'Robidoux',
            28 => 'RobidouxSharp',
            29 => 'Cosine',
            30 => 'Spline',
            31 => 'LanczosRadius',
            default => 'Undefined',
        };
    }

    private static function runCommand(string $cmd): ?string
    {
        if ('' === $cmd) {
            return null;
        }
        $out = [];
        $code = 0;
        exec($cmd.' 2>/dev/null', $out, $code);
        if (0 !== $code) {
            return null;
        }

        return implode("\n", $out);
    }
}
