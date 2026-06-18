<?php
enum E: int { case A = 1; }
set_error_handler(static fn (): bool => true);
var_export((int) E::A);
echo "\n";
$last = error_get_last();
var_export($last === null ? 'no warning' : ($last['message'] ?? 'warn'));
