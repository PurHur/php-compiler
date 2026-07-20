<?php
// Guard #20176/#21189 — null needle soft-null for strstr/strpos; siblings still TypeError
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
foreach ([
    'strstr' => static fn () => strstr('abc', null),
    'stristr' => static fn () => stristr('Abc', null),
    'strpos' => static fn () => strpos('abc', null),
    'strrpos' => static fn () => strrpos('abc', null),
    'stripos' => static fn () => stripos('abc', null),
    'strripos' => static fn () => strripos('abc', null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "$label: OK ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
