<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\Web\ResponseContext;

/**
 * fastcgi_finish_request() — flush response and continue (ext/standard/basic_functions.c, issue #3466).
 *
 * php-src: main/fastcgi.c — fcgi_flush(), fcgi_close(); returns false when not FastCGI SAPI.
 */
final class VmFastCgi
{
    private const FCGI_ACTIVE_ENV = 'PHP_COMPILER_FCGI_ACTIVE';

    public static function isFastCgiActive(): bool
    {
        $flag = getenv(self::FCGI_ACTIVE_ENV);

        return false !== $flag && '1' === $flag;
    }

    /** Mark the current process as serving a FastCGI request (lib/Web/FastCgi/RequestHandler). */
    public static function markFastCgiRequestActive(): void
    {
        putenv(self::FCGI_ACTIVE_ENV.'=1');
        $_ENV[self::FCGI_ACTIVE_ENV] = '1';
        $_SERVER[self::FCGI_ACTIVE_ENV] = '1';
    }

    public static function clearFastCgiRequestActive(): void
    {
        putenv(self::FCGI_ACTIVE_ENV);
        unset($_ENV[self::FCGI_ACTIVE_ENV], $_SERVER[self::FCGI_ACTIVE_ENV]);
    }

    public static function finishRequest(): bool
    {
        if (!self::isFastCgiActive()) {
            return false;
        }

        while (OutputBuffer::getLevel() > 0) {
            OutputBuffer::endFlush();
        }
        OutputBuffer::flush();
        ResponseContext::markFastCgiRequestFinished();

        return true;
    }
}
