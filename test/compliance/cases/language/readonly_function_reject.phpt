--TEST--
Language: readonly function declaration rejected — php-src parse error (#10012)
--FILE--
<?php
readonly function f(): int { return 1; }
echo f();
--EXPECT_EXIT--
255
