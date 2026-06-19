<?php
declare(strict_types=1);

$di = DateInterval::createFromDateString('1 day');
var_export($di instanceof DateInterval);
echo "\n";
if ($di !== false) {
    var_export($di->format('%d'));
    echo "\n";
}

$bad = DateInterval::createFromDateString('not an interval');
var_export($bad);
echo "\n";

try {
    DateInterval::createFromDateString([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
