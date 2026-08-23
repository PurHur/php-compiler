<?php

declare(strict_types=1);

/**
 * Minimal repro for #33975 — throw non-Throwable under thin AOT must Error, not SIGSEGV.
 *
 * @see php-src Zend/zend_exceptions.c zend_throw_exception_internal
 */
class U
{
}

throw new U();
