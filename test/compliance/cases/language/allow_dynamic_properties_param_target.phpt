--TEST--
Language: #[\AllowDynamicProperties] on parameter compile-time fatal (#5137)
--FILE--
<?php
function f(#[\AllowDynamicProperties] int $x) {}
echo "compiled\n";
--EXPECT_EXIT--
255
