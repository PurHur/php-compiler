--TEST--
AOT checkdate() — calendar validation (#3292)
--FILE--
<?php
echo checkdate(2, 29, 2024) ? 'true' : 'false', "\n";
echo checkdate(2, 29, 2023) ? 'true' : 'false', "\n";
--EXPECT--
true
false
