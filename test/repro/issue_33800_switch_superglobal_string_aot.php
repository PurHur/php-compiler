<?php

declare(strict_types=1);

/**
 * Switch-on-string from superglobal: === true but switch hits default (#33800).
 */
$fromGet = $_GET['route'] ?? 'home';
$fromLit = 'home';

echo 'get_eq=' . ($fromGet === 'home' ? 'yes' : 'no') . "\n";
echo 'lit_eq=' . ($fromLit === 'home' ? 'yes' : 'no') . "\n";
echo 'cross_eq=' . ($fromGet === $fromLit ? 'yes' : 'no') . "\n";

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
