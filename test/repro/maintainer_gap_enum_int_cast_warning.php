<?php
enum E: int { case A = 1; }
set_error_handler(static fn (): bool => true);
var_export((int) E::A);
echo "\n";
var_export(error_get_last()['message'] ?? 'no warning');
