--TEST--
AOT: explode() null separator deprecate then ValueError abort (#25942)
--FILE--
<?php
error_reporting(E_ALL);
explode(null, 'a');
--EXPECT--
--EXPECT_EXIT--
134
