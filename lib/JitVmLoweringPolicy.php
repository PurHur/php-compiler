<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Whole-script MCJIT deferral diagnostics for bin/jit.php and serve-jit (#36222).
 */
final class JitVmLoweringPolicy
{
    public static function jitRequireEnabled(): bool
    {
        $v = $_SERVER['PHP_COMPILER_JIT_REQUIRE'] ?? $_ENV['PHP_COMPILER_JIT_REQUIRE'] ?? getenv('PHP_COMPILER_JIT_REQUIRE');
        if (false === $v || null === $v || '' === $v) {
            return false;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function formatDeferralLine(string $reason): string
    {
        return 'phpc: JIT deferred to VM: '.$reason;
    }

    /**
     * Print each whole-script deferral reason once to stderr.
     *
     * @return bool true when MCJIT was skipped for the script (caller runs VM only)
     */
    public static function announceWholeScriptVmFallback(?Block $block): bool
    {
        $reasons = Block::requiresVmLoweringReasons($block);
        if ([] === $reasons) {
            return false;
        }
        foreach ($reasons as $reason) {
            fwrite(STDERR, self::formatDeferralLine($reason)."\n");
        }
        if (self::jitRequireEnabled()) {
            exit(1);
        }

        return true;
    }
}
