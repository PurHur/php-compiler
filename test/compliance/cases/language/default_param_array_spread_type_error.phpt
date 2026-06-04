--TEST--
Default parameter array spread incompatible with scalar type (#5347)
--FILE--
<?php
function g(int $x = [...[1]]) {}
--EXPECT_EXIT--
255
