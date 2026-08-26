<?php
/**
 * foreach over IteratorAggregate whose getIterator() yields — AOT must match Zend (#34980).
 *
 * php-src: Zend/zend_interfaces.c zend_user_it_get_new_iterator; Zend/zend_generators.c
 */
error_reporting(E_ALL);

class A implements IteratorAggregate
{
    public function getIterator(): Traversable
    {
        yield 'k' => 1;
    }
}

foreach (new A() as $k => $v) {
    echo $k, $v, "\n";
}
