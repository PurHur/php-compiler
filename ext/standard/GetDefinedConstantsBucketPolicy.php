<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * get_defined_constants(true) bucket materialization vs Zend reference profile (#17896, basic_functions.c).
 *
 * Constants may remain registered for constant()/defined() while omitted from categorized buckets
 * on the 8.2 reference harness (forward-profile-only symbols).
 */
final class GetDefinedConstantsBucketPolicy
{
    /** @var array<string, true>|null */
    private static ?array $standardExclude = null;

    /**
     * Standard-bucket names withheld from categorized output on the reference profile.
     *
     * @return array<string, true>
     */
    public static function standardBucketExcludeNames(): array
    {
        if (null !== self::$standardExclude) {
            return self::$standardExclude;
        }

        $exclude = [
            // Legacy constantFetch alias; Zend exposes STREAM_IPPROTO_IP only.
            'STREAM_IPROTO_IP' => true,
            // Registered for phpinfo()/credits paths; not in Zend 8.2 categorized map.
            'DEBUG_BACKTRACE_IGNORE_STATIC_ARGS' => true,
            'CREDITS_WEB' => true,
            'LOG_FTP' => true,
            'IMAGETYPE_HEIF' => true,
        ];

        if (!self::categorizesPhp84Constants()) {
            $exclude['ARRAY_PAD_LEFT'] = true;
            $exclude['ARRAY_PAD_RIGHT'] = true;
            $exclude['ARRAY_PAD_BOTH'] = true;
            $exclude['PHP_ROUND_CEILING'] = true;
            $exclude['PHP_ROUND_FLOOR'] = true;
            $exclude['PHP_ROUND_TOWARD_ZERO'] = true;
            $exclude['PHP_ROUND_AWAY_FROM_ZERO'] = true;
        }

        if (!CompilerVersion::advertisesStreamSupports()) {
            $exclude['STREAM_LOCK'] = true;
            $exclude['STREAM_FILTER'] = true;
            $exclude['STREAM_META_SEEKABLE'] = true;
        }

        self::$standardExclude = $exclude;

        return self::$standardExclude;
    }

    private static function categorizesPhp84Constants(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }
}
