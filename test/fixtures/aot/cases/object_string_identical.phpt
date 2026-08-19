--TEST--
AOT: object vs string/int/bool === / !== must verify and match Zend zend_is_identical (#32523)
--FILE--
<?php
echo ((new stdClass()) === "a") ? "y\n" : "n\n";
echo ((new stdClass()) !== "a") ? "y\n" : "n\n";
echo ("a" === new stdClass()) ? "y\n" : "n\n";
echo ("a" !== new stdClass()) ? "y\n" : "n\n";
echo ((new stdClass()) === 1) ? "y\n" : "n\n";
echo ((new stdClass()) !== true) ? "y\n" : "n\n";
--EXPECT--
n
y
n
y
n
y
--EXPECT_EXIT--
0
