<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * SAPI gating for Apache-style request header builtins (issue #11780).
 *
 * php-src: ext/standard/head.c — getallheaders/apache_request_headers registered
 * only for apache/apache2handler/cli-server, not generic cli.
 */
final class VmHead
{
    /** @var list<string> */
    private const WEB_SAPIS_WITH_HEADERS = [
        'apache',
        'apache2handler',
        'cli-server',
    ];

    public static function registersRequestHeaderFunctions(): bool
    {
        if (\in_array(CompilerVersion::SAPI, self::WEB_SAPIS_WITH_HEADERS, true)) {
            return true;
        }

        $requestMethod = \getenv('REQUEST_METHOD');

        return false !== $requestMethod && '' !== $requestMethod;
    }
}
