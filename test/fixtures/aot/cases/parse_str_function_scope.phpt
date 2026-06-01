--TEST--
AOT: parse_str() one-arg inside function is fatal when uncaught (#4034)
--FILE--
<?php
function t(): void {
    parse_str('a=1');
}
t();
--EXPECT--
--EXPECT_EXIT--
255
