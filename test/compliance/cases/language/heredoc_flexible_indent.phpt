--TEST--
Language: flexible heredoc indentation stripping (issue #3636)
--FILE--
<?php
echo <<<EOT
    hello
    world
    EOT;
--EXPECT--
hello
world
