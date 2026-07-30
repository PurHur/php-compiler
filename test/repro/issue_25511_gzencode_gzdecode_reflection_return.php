<?php
declare(strict_types=1);

// #25511: gzencode/gzdecode Reflection return string|false.
foreach (['gzencode', 'gzdecode'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
echo 'round=', gzdecode(gzencode('hi')), "\n";
