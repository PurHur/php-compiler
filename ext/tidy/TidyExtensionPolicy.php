<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

/**
 * ext/tidy surface advertisement (php-src ext/tidy/tidy.c; #21464, #23955).
 *
 * Withhold extension_loaded() / function_exists() / class_exists() on the reference
 * harness when host Zend has no ext/tidy — same shape as soap/yaml (#22859).
 * Runtime work delegates to host Zend ext/tidy when {@see VmTidy::hostAvailable()}.
 */
final class TidyExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return VmTidy::hostAvailable();
    }

    /** Compliance filenames that exercise tidy builtins / classes. */
    public static function isTidyComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'tidy_')
            || str_contains($testFileName, 'tidynode');
    }

    /** Phantom-registration guards that assert tidy is withheld (#23955). */
    public static function isTidyPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'tidy_phantom');
    }

    /** Run functional tidy compliance when advertised, else phantom only (#23955). */
    public static function runsTidyCompliance(string $testFileName): bool
    {
        if (self::advertisesExtension()) {
            return !self::isTidyPhantomComplianceCase($testFileName);
        }

        return self::isTidyPhantomComplianceCase($testFileName);
    }
}
