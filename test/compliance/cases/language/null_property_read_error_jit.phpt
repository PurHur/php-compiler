--TEST--
Language: read property on null throws Error — JIT (#7431)
--JIT--
--FILE--
<?php
$x = null;
$y = $x->prop;
--EXPECT_EXIT--
255
