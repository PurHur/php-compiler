<?php
// Issue #28441 — PROFILE=8.5 (void) statement cast suppresses #[\NoDiscard]
ini_set('error_reporting', '32767');
error_clear_last();
#[\NoDiscard]
function must_use(): int
{
    return 42;
}
(void) must_use();
$last = error_get_last();
echo null === $last ? "ok\n" : (($last['message'] ?? 'warn')."\n");
