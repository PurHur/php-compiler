<?php

declare(strict_types=1);

function probe(string $class): void
{
    $e = new $class('probe');
    foreach (['getTrace', 'getTraceAsString', '__toString'] as $m) {
        echo $class, '::', $m, ' ';
        try {
            $r = '__toString' === $m ? (string) $e : $e->$m();
            echo is_array($r) ? 'array(' . count($r) . ')' : 'string', "\n";
        } catch (Throwable $t) {
            echo get_class($t), "\n";
        }
    }
}

probe(Exception::class);
probe(Error::class);
probe(TypeError::class);
