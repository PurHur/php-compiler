<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Host-side stand-in for PHP 8.4 RequestParseBodyException (#5965).
 *
 * Host PHP 8.2 does not ship the real class; throwing a namespaced Error/Exception
 * subclass lets executeInternalHandler re-materialize the VM builtin class without
 * flattening to generic Exception.
 *
 * php-src: ext/standard/http.c
 */
final class NativeRequestParseBodyException extends \Exception
{
}
