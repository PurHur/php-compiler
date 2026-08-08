--TEST--
Language: FiberError/ArithmeticError/ArgumentCountError getFile()/getLine() match user site (#28832)
--FILE--
<?php
$f = new Fiber(fn() => 1);
$f->start();
try {
    $f->resume();
} catch (FiberError $e) {
    echo $e->getFile() !== '' ? "fiber_file_ok\n" : "fiber_file_bad\n";
    echo $e->getLine() >= 1 ? "fiber_line_ok\n" : "fiber_line_bad\n";
}
try {
    echo 1 << -1;
} catch (ArithmeticError $e) {
    echo $e->getFile() !== '' ? "arith_file_ok\n" : "arith_file_bad\n";
    echo $e->getLine() >= 1 ? "arith_line_ok\n" : "arith_line_bad\n";
}
try {
    strlen('a', 'b');
} catch (ArgumentCountError $e) {
    echo $e->getFile() !== '' ? "argc_file_ok\n" : "argc_file_bad\n";
    echo $e->getLine() >= 1 ? "argc_line_ok\n" : "argc_line_bad\n";
}
try {
    strlen([]);
} catch (TypeError $e) {
    echo $e->getFile() !== '' ? "type_file_ok\n" : "type_file_bad\n";
    echo $e->getLine() >= 1 ? "type_line_ok\n" : "type_line_bad\n";
}
--EXPECT--
fiber_file_ok
fiber_line_ok
arith_file_ok
arith_line_ok
argc_file_ok
argc_line_ok
type_file_ok
type_line_ok
