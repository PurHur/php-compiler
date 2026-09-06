<?php
/**
 * #36382 — entry for IncludeHelper + callable `$cb()` across units.
 */
use function FastRoute\simpleDispatcher;

$dispatcher = simpleDispatcher(function ($r) {
    $r->addRoute('GET', '/hello', 'hello_id');
});
$res = $dispatcher->dispatch('GET', '/hello');
echo $res[0], ':', $res[1], "\n";
echo "OK\n";
