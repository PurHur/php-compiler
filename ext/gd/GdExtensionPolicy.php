<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * ext/gd surface advertisement — php-src ext/gd/gd.c (#11675, #6215, #22740).
 *
 * PHP-in-PHP decode/draw implementations stay in-tree, but Zend never registers
 * {@code gd_info} / {@code imagecreate} / {@code GdImage} unless ext/gd is linked.
 * Advertise the logical {@code gd} module only when host Zend has php-gd
 * ({@see advertisesExtension()}) — same host-extension gate as #22691 (intl) /
 * #11627 (curl). Do not phantom-advertise on images without php-gd (#22740).
 */
final class GdExtensionPolicy
{
    /**
     * extension_loaded('gd') / CREDITS_MODULES — match host Zend php-gd (#22740, re-#11675).
     *
     * php-src-strict: Docker reference image ships without php-gd; withhold stubs
     * so function_exists / get_extension_funcs agree with Zend.
     */
    public static function advertisesExtension(): bool
    {
        return \extension_loaded('gd');
    }

    /**
     * imagecreatefromstring / imagepng / … — only with loaded ext/gd (#6215).
     */
    public static function advertisesDecodeFromString(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * imagecreate / drawing surface — only with loaded ext/gd (#3496, #20415).
     */
    public static function advertisesDrawing(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * Compliance / fixture filenames that exercise ext/gd surface (#22740).
     */
    public static function isGdComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'gd_')
            || str_contains($testFileName, 'extension_loaded_gd')
            || str_contains($testFileName, 'imagecreate')
            || str_contains($testFileName, 'imageavif')
            || str_contains($testFileName, 'imagecrop')
            || str_contains($testFileName, 'imagefilter')
            || str_contains($testFileName, 'imageflip')
            || str_contains($testFileName, 'imagettf')
            || str_contains($testFileName, 'imagewebp');
    }

    /** Phantom-registration guards that assert gd is withheld (#22740). */
    public static function isGdPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'gd_phantom')
            || str_contains($testFileName, 'extension_loaded_gd_phantom');
    }

    /**
     * Run functional gd compliance when ext/gd is advertised, or phantom guards (#22740).
     */
    public static function runsGdCompliance(string $testFileName): bool
    {
        if (self::advertisesExtension()) {
            return !self::isGdPhantomComplianceCase($testFileName);
        }

        return self::isGdPhantomComplianceCase($testFileName);
    }
}
