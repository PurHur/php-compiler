--TEST--
Language: flexible heredoc indent with interpolation (issue #3636)
--FILE--
<?php
$name = 'world';
echo <<<EOT
    hello {$name}
    EOT;
--EXPECT--
hello world
