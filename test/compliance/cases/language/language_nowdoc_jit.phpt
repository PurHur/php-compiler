--TEST--
Language: nowdoc without interpolation JIT (issue #178)
--FILE--
<?php
echo <<<'TAG'
literal {$name}

TAG;
--EXPECT--
literal {$name}
