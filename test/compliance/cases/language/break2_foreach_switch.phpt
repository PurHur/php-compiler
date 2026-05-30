--TEST--
break 2 in switch nested in foreach exits both (#3405, Zend zend_compile.c)
--FILE--
<?php
foreach ([1, 2] as $v) {
    switch ($v) {
        case 1:
            echo "a";
            break 2;
        case 2:
            echo "b";
    }
    echo "x";
}
--EXPECT--
a
