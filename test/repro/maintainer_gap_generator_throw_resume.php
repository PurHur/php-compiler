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
    $traceString = $e->getTraceAsString();
    if (!str_contains($traceString, '[internal function]: g()')) {
        echo 'fail: trace expected [internal function]: g() in '.$traceString."\n";
        exit(1);
    }
    $trace = $e->getTrace();
    if ([] === $trace || !isset($trace[0]['function']) || 'g' !== $trace[0]['function']) {
        echo 'fail: trace[0] function g missing: '.json_encode($trace)."\n";
        exit(1);
    }
    if (isset($trace[0]['file']) || isset($trace[0]['line'])) {
        echo 'fail: generator throw frame must omit file/line: '.json_encode($trace[0])."\n";
        exit(1);
    }
    echo "ok\n";
    exit(0);
}

echo "fail: expected catchable Exception\n";
exit(1);
