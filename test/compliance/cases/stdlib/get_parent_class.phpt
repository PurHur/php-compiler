--TEST--
Stdlib: get_parent_class() — parent from extends chain (VM, #3483)
--FILE--
<?php
class ParentGpc {}
class ChildGpc extends ParentGpc {}

echo get_parent_class(new ChildGpc()), "\n";
echo get_parent_class(ChildGpc::class), "\n";
echo get_parent_class(ParentGpc::class) ? 'has-parent' : 'no-parent';
echo "\n";
--EXPECT--
ParentGpc
ParentGpc
no-parent
