--TEST--
AOT: nowdoc literal (no interpolation) (issue #3187)
--FILE--
<?php
echo <<<'TAG'
{$x}
TAG;
--EXPECT--
{$x}
