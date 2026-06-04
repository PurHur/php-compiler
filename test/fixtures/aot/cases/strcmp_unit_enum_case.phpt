--TEST--
AOT: strcmp() — unit enum case TypeError (#5665)
--FILE--
<?php
enum E { case A; }
strcmp(E::A, '');
--EXPECT--
--EXPECT_EXIT--
134
