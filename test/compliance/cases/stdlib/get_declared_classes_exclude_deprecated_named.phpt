--TEST--
Stdlib: get_declared_classes() exclude_deprecated: named parameter (#4711, basic_functions.c)
--FILE--
<?php
#[\Deprecated]
class DepNamed {}
class OkNamed {}

$filtered = get_declared_classes(exclude_deprecated: true);
echo in_array('OkNamed', $filtered, true) ? "ok-listed\n" : "ok-missing\n";
echo in_array('DepNamed', $filtered, true) ? "dep-listed-bad\n" : "dep-filtered-ok\n";
--EXPECT--
ok-listed
dep-filtered-ok
