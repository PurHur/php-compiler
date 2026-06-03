--TEST--
Standalone true/false parameter types reject non-bool scalars (issue #4784, zend_type_check.c)
--FILE--
<?php
function accepts_true(true $x): void { echo "ok\n"; }
function accepts_false(false $x): void { echo "ok\n"; }
accepts_true(true);
accepts_false(false);
foreach ([['accepts_true', 1], ['accepts_false', 0]] as $case) {
    try {
        $case[0]($case[1]);
    } catch (Throwable $e) {
        echo get_class($e) . "\n";
    }
}
?>
--EXPECT--
ok
ok
TypeError
TypeError
