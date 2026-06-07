--TEST--
Language: #[\CompileTime] on function compile-time fatal (#7300)
--FILE--
<?php
#[\CompileTime]
function f(): void {}
echo "compiled\n";
--EXPECT_EXIT--
255
