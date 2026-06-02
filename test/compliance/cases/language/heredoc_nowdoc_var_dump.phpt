--TEST--
Language: heredoc vs nowdoc var_dump parity (issue #4427)
--FILE--
<?php
$x = "X";
$a = <<<EOT
hello $x
EOT;

$b = <<<'EOT'
hello $x
EOT;

var_dump($a);
var_dump($b);
--EXPECT--
string(7) "hello X"
string(8) "hello $x"
