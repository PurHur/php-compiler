<?php

declare(strict_types=1);

/**
 * #36382 — Exception::__construct $this must not readObject a __string__* slot
 * (ReflectionSetup::loadObjectFromArg). php-src: Zend/zend_exceptions.c.
 */
function f_36382_exception_construct_stringish(): void
{
    try {
        throw new \RuntimeException('boom');
    } catch (\Throwable $e) {
        echo $e->getMessage(), "\n";
    }
}

f_36382_exception_construct_stringish();
