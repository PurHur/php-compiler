<?php
/**
 * get_included_files / get_required_files excess argc → ArgumentCountError (#30654).
 * php-src: ext/standard/basic_functions.c
 */
foreach ([
    'get_included_files' => [
        static fn () => get_included_files(1),
        static fn () => get_included_files(1, 2),
        static fn () => get_included_files(),
    ],
    'get_required_files' => [
        static fn () => get_required_files(1),
        static fn () => get_required_files(1, 2),
        static fn () => get_required_files(),
    ],
] as $name => $calls) {
    foreach ($calls as $i => $fn) {
        try {
            $r = $fn();
            echo $name, '_', $i, ':OK:', is_array($r) ? 'array' : var_export($r, true), "\n";
        } catch (ArgumentCountError $e) {
            echo $name, '_', $i, ':ArgumentCountError:', $e->getMessage(), "\n";
        } catch (Throwable $e) {
            echo $name, '_', $i, ':', get_class($e), ':', $e->getMessage(), "\n";
        }
    }
}
