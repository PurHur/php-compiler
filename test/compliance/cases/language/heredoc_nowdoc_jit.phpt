--TEST--
Language: nowdoc literal JIT (issue #178)
--FILE--
<?php
echo <<<'TAG'
{$x}
TAG;
--EXPECT--
{$x}
