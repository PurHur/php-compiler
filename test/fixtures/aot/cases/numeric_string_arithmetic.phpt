--TEST--
AOT: numeric-string arithmetic +/−/*/÷ with native int (#31967)
--FILE--
<?php
var_dump("5" + 5);
var_dump(3 + "4");
var_dump("10" - "3");
var_dump("6" * "7");
var_dump("10" / "4");
var_dump(NAN <=> 1.0);
--EXPECT--
int(10)
int(7)
int(7)
int(42)
float(2.5)
int(1)
