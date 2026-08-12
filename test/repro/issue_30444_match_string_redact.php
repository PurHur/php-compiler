<?php
// Issue #30444 — UnhandledMatchError string subjects redacted by default (Zend 8.2 parity)
try {
    match("secret-token") { "other" => 1 };
} catch (\UnhandledMatchError $e) {
    echo $e->getMessage() . "\n";
}
try {
    match(42) { 1 => "a" };
} catch (\UnhandledMatchError $e) {
    echo $e->getMessage() . "\n";
}
try {
    match(true) { false => 1 };
} catch (\UnhandledMatchError $e) {
    echo $e->getMessage() . "\n";
}
