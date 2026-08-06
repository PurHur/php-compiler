<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM;

/**
 * PHP 8.5+ curl_close / curl_share_close #[\Deprecated] notices (php-src ext/curl/curl.stub.php; #28133).
 *
 * Zend message: "Function curl_close() is deprecated since 8.5, as it has no effect since PHP 8.0"
 * (E_DEPRECATED via stub attribute — same shape as xml_set_object / #21522).
 */
final class CurlCloseDeprecation
{
    private const MESSAGE = 'as it has no effect since PHP 8.0';

    private const SINCE = '8.5';

    public static function emitClose(?Frame $frame): void
    {
        self::emit($frame, 'curl_close');
    }

    public static function emitShareClose(?Frame $frame): void
    {
        self::emit($frame, 'curl_share_close');
    }

    private static function emit(?Frame $frame, string $function): void
    {
        if (!CompilerVersion::supportsCurlCloseDeprecation()) {
            return;
        }
        $meta = new DeprecatedMetadata(self::MESSAGE, self::SINCE);
        $message = $meta->formatFunction($function);
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $vm->context->errors->internalDeprecated($message, $vm->context, $frame);
    }
}
