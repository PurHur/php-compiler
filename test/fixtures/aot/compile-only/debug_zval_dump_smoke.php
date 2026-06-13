<?php

// Compile-only (#6084): debug_zval_dump() JIT/AOT lowering links on user-script path.
$a = 'hello';
debug_zval_dump($a);
$b = 42;
debug_zval_dump($b);
