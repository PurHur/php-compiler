--TEST--
Language: #[\AllowDynamicProperties] on enum compile-time fatal (#9734)
--FILE--
<?php
#[\AllowDynamicProperties]
enum Bad: int {
    case X = 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
