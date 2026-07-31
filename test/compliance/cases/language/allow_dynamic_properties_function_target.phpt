--TEST--
Language: #[\AllowDynamicProperties] on function compile-time fatal (#25721)
--FILE--
<?php
#[\AllowDynamicProperties]
function f() { return 1; }
echo f(), "\n";
--EXPECT_EXIT--
255
