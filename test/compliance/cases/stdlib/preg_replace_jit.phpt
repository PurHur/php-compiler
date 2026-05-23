--TEST--
JIT: preg_replace() via __compiler_preg_replace (issue #1176)
--FILE--
<?php
echo preg_replace('/\d+/', 'X', 'abc123def456'), "\n";
echo preg_replace('#\.php$#', '.inc', 'lib/Foo.php'), "\n";
$bad = preg_replace('(bad[pattern', 'x', 'hello');
echo $bad === false ? 'false' : 'bad', "\n";
--EXPECT--
abcXdefX
lib/Foo.inc
false
