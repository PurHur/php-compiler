--TEST--
Stdlib: get_declared_* optional exclude_deprecated filters #[Deprecated] symbols (#12177, basic_functions.c)
--FILE--
<?php
#[\Deprecated]
interface DepIface {}
interface OkIface {}
#[\Deprecated]
trait DepTrait {}
trait OkTrait {}
#[\Deprecated]
class DepClass {}
class OkClass {}

echo in_array('DepIface', get_declared_interfaces(), true) ? "iface-dep-listed\n" : "iface-dep-missing\n";
echo in_array('DepIface', get_declared_interfaces(true), true) ? "iface-dep-filtered-bad\n" : "iface-dep-filtered-ok\n";
echo in_array('OkIface', get_declared_interfaces(true), true) ? "iface-ok-listed\n" : "iface-ok-missing\n";
echo in_array('DepTrait', get_declared_traits(), true) ? "trait-dep-listed\n" : "trait-dep-missing\n";
echo in_array('DepTrait', get_declared_traits(true), true) ? "trait-dep-filtered-bad\n" : "trait-dep-filtered-ok\n";
echo in_array('OkTrait', get_declared_traits(true), true) ? "trait-ok-listed\n" : "trait-ok-missing\n";
echo in_array('DepClass', get_declared_classes(), true) ? "class-dep-listed\n" : "class-dep-missing\n";
echo in_array('DepClass', get_declared_classes(true), true) ? "class-dep-filtered-bad\n" : "class-dep-filtered-ok\n";
echo in_array('OkClass', get_declared_classes(true), true) ? "class-ok-listed\n" : "class-ok-missing\n";
--EXPECT--
iface-dep-listed
iface-dep-filtered-ok
iface-ok-listed
trait-dep-listed
trait-dep-filtered-ok
trait-ok-listed
class-dep-listed
class-dep-filtered-ok
class-ok-listed
