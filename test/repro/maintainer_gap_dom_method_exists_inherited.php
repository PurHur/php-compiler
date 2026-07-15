<?php

declare(strict_types=1);

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

$fail = false;
foreach ([
    ['DOMElement', 'after'],
    ['DOMElement', 'append'],
    ['DOMNode', 'appendChild'],
    ['DOMNode', 'contains'],
] as [$class, $method]) {
    if (!method_exists($class, $method)) {
        echo "{$class}::{$method} missing\n";
        $fail = true;
    }
}

if ($fail) {
    exit(1);
}

echo "dom_method_exists_inherited_ok=1\n";
