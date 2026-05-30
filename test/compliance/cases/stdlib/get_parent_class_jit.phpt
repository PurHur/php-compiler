--TEST--
Stdlib: get_parent_class() — parent from extends chain (JIT, #3483)
--FILE--
<?php
class ParentGpcJit {}
class ChildGpcJit extends ParentGpcJit {}

echo get_parent_class(new ChildGpcJit()), "\n";
echo get_parent_class(ChildGpcJit::class), "\n";
echo get_parent_class(ParentGpcJit::class) ? 'has-parent' : 'no-parent';
echo "\n";
--EXPECT--
ParentGpcJit
ParentGpcJit
no-parent
