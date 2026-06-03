--TEST--
AOT: strlen() — unit enum case TypeError (#5119)
--FILE--
<?php
enum E { case A; }
strlen(E::A);
--EXPECT--
--EXPECT_EXIT--
134
