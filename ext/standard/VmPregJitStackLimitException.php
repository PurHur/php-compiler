<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** Pure-PHP PCRE engine JIT stack limit exhausted (ext/pcre/php_pcre.c PREG_JIT_STACKLIMIT_ERROR). */
final class VmPregJitStackLimitException extends \RuntimeException
{
}
