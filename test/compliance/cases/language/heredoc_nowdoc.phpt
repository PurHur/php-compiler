--TEST--
Language: nowdoc literal (no interpolation) (issue #178)
--FILE--
<?php
echo <<<'TAG'
{$x}
TAG;
--EXPECT--
{$x}
