--TEST--
stdlib fprintf() array %s operand — Warning + Array output (#13598, ext/standard/sprintf.c)
--FILE--
<?php
@fprintf(STDOUT, '%s', []);
$err = error_get_last();
echo 'output_ok ', ($err['message'] ?? ''), "\n";
--EXPECT--
Arrayoutput_ok Array to string conversion
