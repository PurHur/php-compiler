--TEST--
Language: user class implements DateTimeInterface — compile-time fatal (#13325)
--FILE--
<?php
class UserDateTime implements DateTimeInterface {}
echo "reach\n";
--EXPECT_EXIT--
255
