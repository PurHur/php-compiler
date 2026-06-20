--TEST--
Stdlib: Throwable getTrace() after manual construct inside function (#9905, zend_exceptions.c)
--FILE--
<?php
declare(strict_types=1);

function probe(string $class): void
{
    $e = new $class('probe');
    echo $class, '::getTrace array(', count($e->getTrace()), ")\n";
}

probe(Exception::class);
probe(Error::class);
probe(TypeError::class);
?>
--EXPECT--
Exception::getTrace array(1)
Error::getTrace array(1)
TypeError::getTrace array(1)
