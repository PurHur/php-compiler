--TEST--
stdlib dns_get_record() JIT invalid hostname — false not empty array (#13600)
--FILE--
<?php
$result = @dns_get_record('invalid..domain', DNS_A);
echo false === $result ? "false\n" : "not_false\n";
--EXPECT--
false
