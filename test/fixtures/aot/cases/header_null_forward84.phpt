--TEST--
AOT: header(null) soft-null on 8.4 forward profile (#21234, reverts #19224)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$h = null;
header($h); // DEP+coerce to ''; must not TypeError
header('Content-Type: text/plain'); // keep CGI flush well-defined after empty soft-null
echo "OK\n";
--EXPECT--
OK
