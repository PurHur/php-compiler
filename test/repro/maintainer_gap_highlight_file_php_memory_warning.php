<?php

// Issue #12095 — highlight_file/show_source on php://memory must not emit E_WARNING.
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$expected = '<code><span style="color: #000000">'."\n".'</span>'."\n".'</code>';
$r = highlight_file('php://memory', true);
echo $r === $expected ? "highlight_ok\n" : "highlight_bad\n";
$highlightWarnings = count($warnings);
$warnings = [];
$s = show_source('php://memory', true);
echo $s === $expected ? "show_source_ok\n" : "show_source_bad\n";
echo 'warnings=', $highlightWarnings + count($warnings), "\n";
