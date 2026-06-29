<?php

declare(strict_types=1);

function g(): Generator
{
    yield 1;
    throw new Exception('x');
}

$g = g();
try {
    $g->next();
} catch (Exception $e) {
    $trace = $e->getTrace();
    echo 'trace_frames '.count($trace)."\n";
    $hasGeneratorNext = 0;
    foreach ($trace as $frame) {
        if (isset($frame['function']) && 'next' === $frame['function']
            && isset($frame['class']) && 'Generator' === $frame['class']) {
            $hasGeneratorNext = 1;
        }
    }
    echo 'has_generator_next '.$hasGeneratorNext."\n";
}
