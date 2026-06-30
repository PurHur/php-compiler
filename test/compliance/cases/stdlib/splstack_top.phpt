--TEST--
stdlib SplStack::top() LIFO peek without pop (ext/spl/spl_dllist.c, #14254)
--FILE--
<?php
$stack = new SplStack();
$stack->push('a');
$stack->push('b');
echo $stack->top(), "\n";
echo $stack->count(), "\n";
echo $stack->pop(), "\n";
echo $stack->top(), "\n";
?>
--EXPECT--
b
2
b
a
