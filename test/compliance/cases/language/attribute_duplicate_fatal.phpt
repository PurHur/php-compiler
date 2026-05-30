--TEST--
Language: duplicate attribute compile-time fatal (#3718)
--FILE--
<?php
#[\AllowDynamicProperties]
#[\AllowDynamicProperties]
class C {}
echo "ok\n";
--EXPECT_EXIT--
255
