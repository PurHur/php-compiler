<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * CLI STDIN/STDOUT/STDERR stream constants (php-src main/main.c php_register_stdio_constants).
 *
 * php-in-PHP: bind php://stdio resources at ext/standard bootstrap — no C runtime (#10163).
 */
final class VmStdStreamConstants
{
    /** @var array<string, int> */
    private const STREAMS = [
        'STDIN' => ['php://stdin', 'rb'],
        'STDOUT' => ['php://stdout', 'wb'],
        'STDERR' => ['php://stderr', 'wb'],
    ];

    public static function register(Context $ctx): void
    {
        foreach (self::STREAMS as $name => [$uri, $mode]) {
            if ($ctx->isUserConstantDefined($name)) {
                continue;
            }
            $handle = VmFsStdio::open($uri, $mode);
            if (false === $handle) {
                continue;
            }
            VmFs::registerStreamPath($handle, $uri);
            VmFs::registerStreamMode($handle, $mode);
            $var = new Variable();
            $var->streamHandle($handle, $ctx);
            $ctx->defineConstant($name, $var);
        }
    }
}
