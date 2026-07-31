--TEST--
Language: eval()-thrown Error catch must not resume try body (#25816, Zend/zend_execute.c)
--FILE--
<?php
echo "A\n";
try {
    eval('class C implements NoSuchIface {}');
    echo "B_in_try\n";
} catch (Throwable $e) {
    echo "C_in_catch\n";
}
echo "D\n";
// Inner eval try/catch must still run trailing eval opcodes.
eval('try { throw new Exception("x"); } catch (Throwable $e) { echo "inner\n"; } echo "more\n";');
echo "after\n";
--EXPECT--
A
C_in_catch
D
inner
more
after
