--TEST--
AOT: named string args + echo $a.$b must match Zend (not SIGSEGV) (#34457)
--FILE--
<?php
function s($a, $b) {
    echo $a . $b;
}
s(b: "y", a: "x");
echo "\n";
s(a: "x", b: "y");
echo "\n";
function t($a, $b = "B") {
    echo $a . $b;
}
t("x", b: "Y");
echo "\n";
--EXPECT--
xy
xy
xY
