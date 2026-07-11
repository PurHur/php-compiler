--TEST--
stdlib wordwrap() cut=true with spaces breaks at word boundaries (#10195)
--FILE--
<?php
declare(strict_types=1);
echo wordwrap('hello world test string here', 5, "\n", true);
--EXPECT--
hello
world
test
strin
g
here
