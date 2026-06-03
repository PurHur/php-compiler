--TEST--
AOT: gc_enable/gc_disable/gc_enabled toggle (#3209)
--FILE--
<?php
var_export(gc_enabled());
echo "\n";
gc_disable();
var_export(gc_enabled());
echo "\n";
gc_enable();
var_export(gc_enabled());
echo "\n";
--EXPECT--
true
false
true

