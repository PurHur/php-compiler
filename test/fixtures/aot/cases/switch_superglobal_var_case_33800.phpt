--TEST--
AOT: switch on superglobal vs variable case label after prior switch chains (#33800)
--FILE--
<?php
$fromGet = $_GET['route'] ?? 'home';
$fromLit = 'home';

switch ($fromGet) {
    case 'home':
        echo "get_switch=home\n";
        break;
    default:
        echo "get_switch=default\n";
}

switch ($fromLit) {
    case 'home':
        echo "lit_switch=home\n";
        break;
    default:
        echo "lit_switch=default\n";
}

switch ($fromGet) {
    case $fromLit:
        echo "cross_switch=home\n";
        break;
    default:
        echo "cross_switch=default\n";
}
--EXPECT--
get_switch=home
lit_switch=home
cross_switch=home
--EXPECT_EXIT--
0
