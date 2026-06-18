--TEST--
AOT: continue targeting switch emits E_WARNING (#4502, #9214, Zend zend_compile.c)
--FILE--
<?php
switch (1) {
    case 1:
        continue;
}
echo "after\n";
--EXPECT--
after
