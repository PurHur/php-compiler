--TEST--
Language: ClassConstFetch call arg after sibling binary-op keeps next const (#26990, Zend/zend_compile.c)
--FILE--
<?php
class Box {
    public const X = 10;
    public const Y = 20;
    public const Z = 30;
    public function get($n) { return $n; }
}
function take($n) { return $n; }
if (!class_exists('Box')) {
    echo "no\n";
    exit;
}
$b = new Box;
echo $b->get(Box::X), '-', $b->get(Box::Y) + 1, '-', $b->get(Box::Z), "\n";
echo take(Box::X), '-', take(Box::Y) + 1, '-', take(Box::Z), "\n";
echo Box::X, '-', (Box::Y) + 1, '-', Box::Z, "\n";
?>
--EXPECT--
10-21-30
10-21-30
10-21-30
