<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\ProjectManifest;

/**
 * Apply per-compilation-unit language profile before preprocess gates (#17681).
 *
 * Reads {@see ProjectManifest} `languageProfile` or a source pragma
 * `// php-compiler-language-profile=8.4` when {@see getenv()} `PHP_COMPILER_PROFILE`
 * is unset so forward-profile syntax (exit(status:), readonly function, …) parses
 * without ad-hoc CLI env.
 */
final class LanguageProfileScope
{
    private const SOURCE_PRAGMA = '/php-compiler-language-profile\s*=\s*(\d+\.\d+(?:\.\d+)?)/i';

    private ?string $previous = null;

    private bool $applied = false;

    public static function beginForCompilationUnit(string $code, string $filename): self
    {
        $scope = new self();
        if (self::profileEnvIsSet()) {
            return $scope;
        }
        $profile = self::resolveFromManifest($filename) ?? self::resolveFromSourcePragma($code);
        if (null === $profile) {
            return $scope;
        }
        $previous = getenv('PHP_COMPILER_PROFILE');
        $scope->previous = false === $previous ? null : $previous;
        $scope->applied = true;
        putenv('PHP_COMPILER_PROFILE='.$profile);

        return $scope;
    }

    public function end(): void
    {
        if (!$this->applied) {
            return;
        }
        if (null === $this->previous) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->previous);
        }
        $this->applied = false;
    }

    public function wasApplied(): bool
    {
        return $this->applied;
    }

    private static function profileEnvIsSet(): bool
    {
        $raw = getenv('PHP_COMPILER_PROFILE');

        return \is_string($raw) && '' !== trim($raw);
    }

    private static function resolveFromManifest(string $filename): ?string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject('', $filename)) {
            return null;
        }
        $dir = \dirname($filename);
        if (!is_dir($dir)) {
            return null;
        }

        return ProjectManifest::resolveLanguageProfile($dir);
    }

    private static function resolveFromSourcePragma(string $code): ?string
    {
        if (ReferenceProfileTokenScan::exceedsTokenScanBudget($code)) {
            return null;
        }
        $head = substr($code, 0, 4096);
        if (!preg_match(self::SOURCE_PRAGMA, $head, $m)) {
            return null;
        }

        return self::normalizeProfileToken($m[1]);
    }

    private static function normalizeProfileToken(string $raw): ?string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        if (preg_match('/^(\d+\.\d+)$/', $raw, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d+\.\d+\.\d+)/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }
}
