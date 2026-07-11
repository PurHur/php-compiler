<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** Pure-PHP PCRE engine backtrack limit exhausted (ext/pcre/php_pcre.c PREG_BACKTRACK_LIMIT_ERROR). */
final class VmPregBacktrackLimitException extends \RuntimeException
{
}
