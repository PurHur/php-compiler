--TEST--
AOT md5() digest (issue #179)
--FILE--
<?php
echo md5('csrf-token'), "\n";
--EXPECT--
e1846beb0ef64af2e45019ff7d64cd40
