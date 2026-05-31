--TEST--
stdlib wordwrap() cut=true with multi-byte break (issue #3774)
--FILE--
<?php
echo wordwrap('abcdef', 3, '--', true), "\n";
--EXPECT--
abc--def
