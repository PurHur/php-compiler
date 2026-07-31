<?php
// #25910: class constants are case-sensitive (Zend/zend_constants.c)
class ClassConstCase { public const X = 1; }
try {
    echo ClassConstCase::x;
} catch (Throwable $e) {
    echo $e->getMessage();
}
echo "\n";
echo ClassConstCase::X;
echo "\n";
