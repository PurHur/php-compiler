<?php
enum Es: string { case X = 'hi'; }

$tests = [
    'str_contains' => static fn () => str_contains(Es::X, 'h'),
    'str_starts_with' => static fn () => str_starts_with(Es::X, 'h'),
    'str_ends_with' => static fn () => str_ends_with(Es::X, 'i'),
    'trim' => static fn () => trim(Es::X),
    'chop' => static fn () => chop(Es::X),
    'ucwords' => static fn () => ucwords(Es::X),
];

foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo $name, ': NO_TYPEERROR', "\n";
    } catch (TypeError $e) {
        echo $name, ': TypeError', "\n";
    }
}
