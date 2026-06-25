<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Embed-only ob_gzhandler JIT helper — reads $_SERVER Accept-Encoding via HashTable (#9798).
 *
 * Split from {@see ObGzhandlerJitHelper}: AOT standalone nested link cannot JIT-compile
 * HashTable::find() yet; {@see \PHPCompiler\JIT\Builtin\ObGzhandlerStandaloneLlvm} walks sg_SERVER in LLVM.
 */
final class ObGzhandlerServerJitHelper
{
    public static function readAcceptEncodingFromServer(?HashTable $server): string
    {
        if (null === $server) {
            return '';
        }
        $value = $server->find('HTTP_ACCEPT_ENCODING');
        if (null === $value || Variable::TYPE_STRING !== $value->type) {
            return '';
        }

        return $value->toString();
    }
}
