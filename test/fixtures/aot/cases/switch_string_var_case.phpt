--TEST--
AOT: switch on untyped string variable vs string case label (#33800, Zend/zend_operators.c compare_function)
--FILE--
<?php
$route = $_GET['route'] ?? 'home';
switch ($route) {
    case 'home':
        echo "home\n";
        break;
    default:
        echo "default\n";
}
--ENV--
QUERY_STRING=route=home
--EXPECT--
home
--EXPECT_EXIT--
0
