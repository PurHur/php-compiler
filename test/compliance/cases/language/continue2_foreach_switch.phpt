--TEST--
continue 2 in switch nested in foreach targets outer loop (#3405, Zend zend_compile.c)
--FILE--
<?php
foreach ([1] as $v) {
    switch ($v) {
        case 1:
            echo "ok";
            continue 2;
    }
    echo "no";
}
--EXPECT--
ok
