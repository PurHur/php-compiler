--TEST--
AOT wordwrap named break: without width (#28938)
--FILE--
<?php
echo wordwrap('aa bb', break: '-'), "\n";
echo wordwrap(string: 'aa bb', break: '-'), "\n";
echo wordwrap('aa bb', cut_long_words: false), "\n";
echo wordwrap('aa bb', width: 2), "\n";
--EXPECT--
aa bb
aa bb
aa bb
aa
bb
