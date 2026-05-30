--TEST--
__halt_compiler() stops compilation; trailing bytes are not parsed (#3479)
--FILE--
<?php
echo "before halt\n";
__halt_compiler();
?>
TRAILING_BYTES_SHOULD_NOT_BE_PARSED
--EXPECT--
before halt
