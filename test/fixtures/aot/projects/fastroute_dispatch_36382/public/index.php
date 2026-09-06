<?php
/**
 * #36382 — FastRoute simpleDispatcher under IncludeHelper / project AOT.
 * Must print START then 1:hello_id / OK (not abort after START).
 */
use function FastRoute\simpleDispatcher;

require __DIR__ . '/../vendor/autoload.php';

echo "START\n";

$dispatcher = simpleDispatcher(function ($r) {
    $r->addRoute('GET', '/hello', 'hello_id');
});
$res = $dispatcher->dispatch('GET', '/hello');
echo $res[0], ':', $res[1], "\n";
echo "OK\n";
