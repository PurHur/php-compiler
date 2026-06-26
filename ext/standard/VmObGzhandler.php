<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\Web\ResponseContext;
use PHPCompiler\Web\Superglobals;

/**
 * ob_gzhandler() gzip output-buffer handler (ext/zlib/zlib.c, issue #4655).
 *
 * php-src: php_zlib_output_handler_ex / php_zlib_output_encoding
 */
final class VmObGzhandler
{
    private static int $encoding = 0;

    public static function reset(): void
    {
        self::$encoding = 0;
    }

    /**
     * php-src ext/zlib/zlib.c — PHP_FUNCTION(zlib_get_coding_type) (#12280).
     */
    public static function getCodingType(): string|false
    {
        return match (self::resolveEncoding()) {
            \ZLIB_ENCODING_GZIP => 'gzip',
            \ZLIB_ENCODING_DEFLATE => 'deflate',
            default => false,
        };
    }

    public static function handle(string $data, int $mode, ?Context $ctx = null): string
    {
        $encoding = self::resolveEncoding();
        if (0 === $encoding) {
            return self::passthrough($data, $mode);
        }

        if (0 !== ($mode & \PHP_OUTPUT_HANDLER_START)) {
            if (\ZLIB_ENCODING_GZIP === $encoding) {
                ResponseContext::addHeader('Content-Encoding: gzip', true);
                ResponseContext::addHeader('Vary: Accept-Encoding', false);
            }

            return '';
        }

        if (0 !== ($mode & (\PHP_OUTPUT_HANDLER_END | \PHP_OUTPUT_HANDLER_FINAL))) {
            if ('' === $data) {
                return '';
            }
            $compressed = VmZlib::gzencode($data, -1, $encoding);
            if (false === $compressed) {
                return $data;
            }

            return $compressed;
        }

        return '';
    }

    public static function flushBuffer(string $content, ?Context $ctx): string
    {
        self::handle('', \PHP_OUTPUT_HANDLER_START, $ctx);
        $processed = self::handle($content, \PHP_OUTPUT_HANDLER_END, $ctx);

        return '' !== $processed ? $processed : $content;
    }

    private static function passthrough(string $data, int $mode): string
    {
        if (0 !== ($mode & \PHP_OUTPUT_HANDLER_START)) {
            return '';
        }
        if (0 !== ($mode & (\PHP_OUTPUT_HANDLER_END | \PHP_OUTPUT_HANDLER_FINAL))) {
            return $data;
        }

        return '';
    }

    private static function resolveEncoding(): int
    {
        if (0 !== self::$encoding) {
            return self::$encoding;
        }
        $accept = self::acceptEncodingHeader();
        if (null === $accept) {
            return 0;
        }
        $lower = strtolower($accept);
        if (str_contains($lower, 'gzip')) {
            self::$encoding = \ZLIB_ENCODING_GZIP;

            return self::$encoding;
        }
        if (str_contains($lower, 'deflate')) {
            self::$encoding = \ZLIB_ENCODING_DEFLATE;

            return self::$encoding;
        }

        return 0;
    }

    private static function acceptEncodingHeader(): ?string
    {
        $ctx = Superglobals::getActiveContext();
        if (null !== $ctx) {
            $server = $ctx->getSuperglobal('_SERVER');
            if (\PHPCompiler\VM\Variable::TYPE_ARRAY === $server->type) {
                foreach ($server->toArray()->iterateKeyed(false) as [$key, $value]) {
                    if ('HTTP_ACCEPT_ENCODING' === strtoupper((string) $key->toString())) {
                        return $value->toString();
                    }
                }
            }
        }
        if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && \is_string($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            return $_SERVER['HTTP_ACCEPT_ENCODING'];
        }

        return null;
    }
}
