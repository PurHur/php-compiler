<?php

// Issue #23382 — get_browser Reflection/named args are user_agent/return_array (not browser_name).
$rf = new ReflectionFunction('get_browser');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
try {
    // Named bind must succeed; browscap may be unset (false / warning is fine).
    var_export(@get_browser(user_agent: null, return_array: true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    get_browser(browser_name: null);
    echo "unexpected browser_name ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
