<?php
declare(strict_types=1);

// #27712 — mysqli_execute_query / mysqli::execute_query Reflection stubs
echo 'fn=', function_exists('mysqli_execute_query') ? '1' : '0', PHP_EOL;
if (!function_exists('mysqli_execute_query')) {
    exit(0);
}

$r = new ReflectionFunction('mysqli_execute_query');
echo 'arity=', $r->getNumberOfParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', ($p->getType() ? (string) $p->getType() : '?'), ($p->isOptional() ? ' opt' : ''), PHP_EOL;
}
echo 'ret=', $r->getReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;

$m = (new ReflectionClass('mysqli'))->getMethod('execute_query');
echo 'method_params=', count($m->getParameters()), PHP_EOL;
foreach ($m->getParameters() as $p) {
    echo 'm:', $p->getName(), ':', ($p->getType() ? (string) $p->getType() : '?'), ($p->isOptional() ? ' opt' : ''), PHP_EOL;
}
echo 'mret=', $m->getReturnType() ? (string) $m->getReturnType() : 'none', PHP_EOL;

try {
    mysqli_execute_query(mysql: null, query: 'SELECT 1');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
