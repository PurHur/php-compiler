--TEST--
AOT: SplStack::top() peeks LIFO head via __spl_ht (#28704)
--FILE--
<?php
$s = new SplStack();
$s->push(1);
$s->push(2);
$s->push(3);
echo 'top=', $s->top(), "\n";
foreach ($s as $v) {
    echo $v, ',';
}
echo "\n";
echo 'still=', $s->top(), "\n";
echo 'pop=', $s->pop(), "\n";
echo 'then=', $s->top(), "\n";
$d = new SplDoublyLinkedList();
$d->push(10);
$d->push(20);
echo 'ddl_top=', $d->top(), ' ddl_bottom=', $d->bottom(), "\n";
--EXPECT--
top=3
3,2,1,
still=3
pop=3
then=2
ddl_top=20 ddl_bottom=10
