<?php
// Repro #23383 — filter_input Zend stub named parameters (var_name)
$names = [];
foreach ((new ReflectionFunction('filter_input'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = filter_input(type: INPUT_GET, var_name: 'x');
$positional = filter_input(INPUT_GET, 'x');
$ok = ['type', 'var_name', 'filter', 'options'] === $names
    && null === $named
    && $named === $positional;
echo $ok ? "ok\n" : "fail\n";
