--TEST--
AOT: function_exists() literal and dynamic name (issue #1216)
--FILE--
<?php
declare(strict_types=1);
function aot_user_fn(): void
{
}
$key = 'strlen';
echo function_exists('htmlspecialchars') ? "1\n" : "0\n";
echo function_exists($key) ? "1\n" : "0\n";
echo function_exists('aot_user_fn') ? "1\n" : "0\n";
echo function_exists('no_such_fn') ? "1\n" : "0\n";
--EXPECT--
1
1
1
0
--EXPECT_EXIT--
0
