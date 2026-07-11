--TEST--
stdlib error_get_last() — records @-suppressed bare undefined variable read (#13587, ext/standard/basic_functions.c)
--FILE--
<?php
@$undefined_error_get_last_at_read;
$last = error_get_last();
echo is_array($last) && str_contains($last['message'] ?? '', 'Undefined variable') ? 'ok' : 'fail';
echo "\n";
--EXPECT--
ok
