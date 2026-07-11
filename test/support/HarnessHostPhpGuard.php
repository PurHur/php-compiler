<?php

declare(strict_types=1);

/**
 * Refuse bare PHPUnit on Runforge/agent-harness host PHP (memory_limit=-1, no Docker cgroup).
 *
 * Agents sometimes bypass ./script/docker-exec.sh when the CI lock is held; that leaves
 * runaway phpunit processes that can consume 100+ GiB RAM and trip the harness memory gate.
 */
final class HarnessHostPhpGuard
{
    /** Reject host phpunit above this cap even when memory_limit is set (bytes). */
    private const MAX_HOST_MEMORY_BYTES = 8 * 1024 * 1024 * 1024;

    public static function refuseBarePhpunitOnHarnessHost(): void
    {
        if (is_file('/.dockerenv')) {
            return;
        }
        if ('1' === getenv('PHP_COMPILER_ALLOW_HOST_PHPUNIT')) {
            return;
        }
        if (!self::isHarnessHostContext()) {
            return;
        }
        if (self::hasBoundedMemoryLimit()) {
            return;
        }

        fwrite(STDERR, "phpunit: refused on Runforge/harness host with unlimited or excessive memory.\n");
        fwrite(STDERR, "Use ./script/phpunit.sh … or ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && vendor/bin/phpunit …'\n");
        fwrite(STDERR, "Do not rm .php-compiler-ci.lock to run host phpunit — wait for the active Docker CI job.\n");
        fwrite(STDERR, "Opt-out (debug only): PHP_COMPILER_ALLOW_HOST_PHPUNIT=1\n");
        exit(2);
    }

    private static function isHarnessHostContext(): bool
    {
        if (false !== getenv('HARNESS_DOCKER_RUN_OPTS')) {
            return true;
        }
        $host = getenv('HARNESS_HOST');
        if (false !== $host && '' !== $host) {
            return true;
        }

        $cwd = getcwd() ?: '';

        return str_contains($cwd, '/workspaces/');
    }

    private static function hasBoundedMemoryLimit(): bool
    {
        $limit = ini_get('memory_limit');
        if (false === $limit || '' === $limit || '-1' === $limit) {
            return false;
        }
        $bytes = self::parseMemoryLimitBytes((string) $limit);
        if ($bytes <= 0) {
            return false;
        }

        return $bytes <= self::MAX_HOST_MEMORY_BYTES;
    }

    /** @return int bytes, or -1 for unlimited/unknown */
    public static function parseMemoryLimitBytes(string $limit): int
    {
        $limit = trim($limit);
        if ('' === $limit || '-1' === $limit) {
            return -1;
        }
        if (ctype_digit($limit)) {
            return (int) $limit;
        }
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)$/i', $limit, $m)) {
            return -1;
        }
        $value = (float) $m[1];
        $unit = strtoupper($m[2]);
        $mult = match ($unit) {
            'G' => 1024 * 1024 * 1024,
            'M' => 1024 * 1024,
            'K' => 1024,
            default => 1,
        };

        return (int) round($value * $mult);
    }
}
