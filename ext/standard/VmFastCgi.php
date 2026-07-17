<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
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

    /** @var list<string> */
    private const FASTCGI_SAPIS = [
        'fpm-fcgi',
        'cgi-fcgi',
        'fpm',
    ];

    /**
     * Register fastcgi_finish_request() only for FastCGI/FPM SAPIs (#16757).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FALIAS only when PHP_FASTCGI.
     */
    public static function registersFinishRequestFunction(): bool
    {
        if (self::isFastCgiActive()) {
            return true;
        }

        return \in_array(CompilerVersion::SAPI, self::FASTCGI_SAPIS, true);
    }

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

        // Snapshot host SAPI capture (CgiDriver ob_start) as the client body — post-finish
        // echo must not appear in the HTTP response (php-src fpm_main / main/output.c, #6136).
        $body = '';
        if (\ob_get_level() > 0) {
            $contents = \ob_get_contents();
            $body = false !== $contents ? $contents : '';
        }
        ResponseContext::markFastCgiRequestFinished($body);

        return true;
    }
}
