--TEST--
Language: #[\ReturnTypeWillChange] on function — compile-time fatal (#25722)
--FILE--
<?php
#[\ReturnTypeWillChange]
function probe()
{
    return 1;
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "ReturnTypeWillChange" cannot target function (allowed targets: method)
