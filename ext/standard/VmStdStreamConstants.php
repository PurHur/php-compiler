<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * CLI STDIN/STDOUT/STDERR stream constants (php-src main/main.c php_register_stdio_constants).
 *
 * php-in-PHP: bind php://stdio resources at ext/standard bootstrap — no C runtime (#10163).
 *
 * Handles are process-lifetime: each `new Runtime()` used to `fopen` three fresh wrappers and
 * never close them, leaking 3 FDs per FastCGI/CGI request and blocking the 10k soak (#36388).
 * php-src registers stdio once per process; reuse the same VmFs handles across Contexts.
 */
final class VmStdStreamConstants
{
    /** @var array<string, array{0: string, 1: string}> */
    private const STREAMS = [
        'STDIN' => ['php://stdin', 'rb'],
        'STDOUT' => ['php://stdout', 'wb'],
        'STDERR' => ['php://stderr', 'wb'],
    ];

    /** @var array<string, int>|null name → VmFs handle id */
    private static ?array $processHandles = null;

    public static function register(Context $ctx): void
    {
        self::ensureProcessHandles();
        foreach (self::$processHandles as $name => $handle) {
            if ($ctx->isUserConstantDefined($name)) {
                continue;
            }
            $var = new Variable();
            $var->streamHandle($handle, $ctx);
            $ctx->defineConstant($name, $var);
        }
    }

    /**
     * Open stdio once per process (php_register_stdio_constants).
     *
     * @return array<string, int>
     */
    public static function processHandles(): array
    {
        self::ensureProcessHandles();

        return self::$processHandles;
    }

    /** Unit tests only — drop cached ids without fclose of host 0/1/2. */
    public static function resetProcessHandlesForTesting(): void
    {
        self::$processHandles = null;
    }

    private static function ensureProcessHandles(): void
    {
        if (null !== self::$processHandles) {
            return;
        }
        $handles = [];
        foreach (self::STREAMS as $name => [$uri, $mode]) {
            $handle = VmFsStdio::open($uri, $mode);
            if (false === $handle) {
                continue;
            }
            VmFs::registerStreamPath($handle, $uri);
            VmFs::registerStreamMode($handle, $mode);
            $handles[$name] = $handle;
        }
        self::$processHandles = $handles;
    }
}
