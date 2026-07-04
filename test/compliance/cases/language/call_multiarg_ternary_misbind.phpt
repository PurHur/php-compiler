--TEST--
Language: multi-arg call consecutive ?: operands bind independently (#15816, Zend/zend_compile.c)
--FILE--
<?php
function pair(string $a, int $b): void
{
    echo "a={$a} b={$b}\n";
}
pair(true ? 'yes' : 'no', false ? 1 : 2);
echo sprintf("%s-%d", true ? 'yes' : 'no', false ? 1 : 2), "\n";
$x = range(1, 14);
var_dump($x === false ? 'false' : 'array', is_array($x) ? count($x) : null);
?>
--EXPECT--
a=yes b=2
yes-2
string(5) "array"
int(14)
