--TEST--
Internal builtin exceptions outside try still fatal (#4866)
--FILE--
<?php
substr_compare('a', 'b', 0, -1);
?>
--EXPECT_EXIT--
255
