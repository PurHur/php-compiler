--TEST--
Language: flexible nowdoc indentation stripping (issue #3636)
--FILE--
<?php
echo <<<'X'
    literal $var
    X;
--EXPECT--
literal $var
