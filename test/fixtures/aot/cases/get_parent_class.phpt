--TEST--
AOT get_parent_class() on object and class name (issue #3483)
--FILE--
<?php
class ParentGpcAot {}
class ChildGpcAot extends ParentGpcAot {}
$c = new ChildGpcAot();
echo get_parent_class($c) === 'ParentGpcAot' ? '1' : '0';
echo get_parent_class(ChildGpcAot::class) === 'ParentGpcAot' ? '1' : '0';
echo get_parent_class(ParentGpcAot::class) ? '1' : '0';
--EXPECT--
110
