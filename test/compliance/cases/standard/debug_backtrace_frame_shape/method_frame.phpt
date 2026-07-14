--TEST--
Stdlib: debug_backtrace() method frames expose class, type, and bare function (#18881)
--FILE--
<?php
class C {
    public function f(): array {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    }
}
$frame = (new C)->f();
echo $frame['class'], "\n";
echo $frame['type'], "\n";
echo $frame['function'], "\n";
--EXPECT--
C
->
f
