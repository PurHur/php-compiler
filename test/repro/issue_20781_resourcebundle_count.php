<?php
// Repro #20781 — resourcebundle_count() procedural alias (php-src-strict).
echo 'fn=', function_exists('resourcebundle_count') ? '1' : '0', "\n";
$rb = ResourceBundle::create('en', 'ICUDATA-zone');
if (false === $rb || null === $rb) {
    // Fallback package when ICUDATA-zone missing in this ICU build.
    $rb = ResourceBundle::create('en', null);
}
echo 'method=', $rb->count(), "\n";
echo 'proc=', resourcebundle_count($rb), "\n";
echo 'countable=', (int) ($rb instanceof Countable), "\n";
echo 'match=', (int) ($rb->count() === resourcebundle_count($rb)), "\n";
