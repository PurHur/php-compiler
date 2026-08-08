--TEST--
Language: anonymous class implementing interface named Interface@anonymous (#28840, zend_compile.c)
--FILE--
<?php
$o = new class implements Countable {
    public function count(): int
    {
        return 0;
    }
};
$n = get_class($o);
if (!preg_match('/^Countable@anonymous\x00.+:\d+\$\d+$/', $n)) {
    echo 'name_fail:', bin2hex($n), "\n";
    exit(1);
}
echo preg_replace("/\0.*/", '', $n), "\n";
echo get_debug_type($o), "\n";
echo preg_replace("/\0.*/", '', (new ReflectionClass($o))->getName()), "\n";

interface A28840 {}
interface B28840 {}
class Base28840 {}

$multi = new class implements A28840, B28840 {};
echo preg_replace("/\0.*/", '', get_class($multi)), "\n";

$ext = new class extends Base28840 implements A28840 {};
echo preg_replace("/\0.*/", '', get_class($ext)), "\n";

$plain = new class {};
echo preg_replace("/\0.*/", '', get_class($plain)), "\n";
--EXPECT--
Countable@anonymous
Countable@anonymous
Countable@anonymous
A28840@anonymous
Base28840@anonymous
class@anonymous
