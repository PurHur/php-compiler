--TEST--
get_browser Reflection user_agent/return_array + named args (#23382, php-src-strict)
--FILE--
<?php
$rf = new ReflectionFunction('get_browser');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
var_export(@get_browser(user_agent: null, return_array: true));
echo "\n";
try {
    get_browser(browser_name: null);
    echo "unexpected browser_name ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
user_agent,return_array
false
Error
