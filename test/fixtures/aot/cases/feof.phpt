--TEST--
AOT: feof() invalid handle (issue #1188)
--FILE--
<?php
echo feof(-999) ? '1' : '0';
--EXPECT--
1
