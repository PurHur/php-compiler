<?php
/**
 * #23460 — escapeshellarg/escapeshellcmd named arg / command params (ext/standard/exec.c)
 */
try {
    echo escapeshellarg(arg: 'a b'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo escapeshellcmd(command: 'ls $a'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
