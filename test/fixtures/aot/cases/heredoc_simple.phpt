--TEST--
AOT: heredoc without interpolation (issue #3187)
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
