--TEST--
Language: heredoc without interpolation JIT (issue #178)
--FILE--
<?php
$x = <<<EOT
hello
world
EOT;
echo $x;
--EXPECT--
hello
world
