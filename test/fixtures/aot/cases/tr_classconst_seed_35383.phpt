--TEST--
Transliterator::FORWARD ClassConstFetch seeds for thin AOT (#35383)
--FILE--
<?php
echo 'FORWARD=', Transliterator::FORWARD, "\n";
echo 'REVERSE=', Transliterator::REVERSE, "\n";
--EXPECT--
FORWARD=0
REVERSE=1
