--TEST--
stdlib highlight_file() php://memory with return=true — empty HTML not false (#12032, ext/standard/url.c)
--FILE--
<?php
set_error_handler(static fn (): bool => true);
$r = highlight_file('php://memory', true);
echo is_string($r) ? 'string' : gettype($r);
echo "\n";
echo strlen($r), "\n";
?>
--EXPECT--
string
51
