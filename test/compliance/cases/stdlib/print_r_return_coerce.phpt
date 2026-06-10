--TEST--
stdlib print_r() — second arg weak bool coercion (#4444)
--FILE--
<?php
echo print_r(['k' => 'v'], 1);
--EXPECT--
Array
(
    [k] => v
)
