--TEST--
language: ternary with function call in test and alternate (#3790)
--FILE--
<?php
putenv('FOO=bar');
putenv('FOO');
echo getenv('FOO') === false ? 'unset' : getenv('FOO');
echo "\n";
putenv('FOO=bar');
echo getenv('FOO') === false ? 'unset' : getenv('FOO');
--EXPECT--
unset
bar
