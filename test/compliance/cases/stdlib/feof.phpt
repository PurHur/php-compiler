--TEST--
stdlib feof() invalid handle is EOF (issue #1188)
--FILE--
<?php
echo feof(-999) ? '1' : '0';
--EXPECT--
1
