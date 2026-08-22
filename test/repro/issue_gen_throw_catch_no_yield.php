<?php
// #33726 — Generator::throw into catch that does not yield again (Zend/zend_generators.c)
function g() {
    try {
        yield 1;
    } catch (Exception $e) {
        echo 'C'.$e->getMessage(), "\n";
    }
}
$gen = g();
$gen->current();
$gen->throw(new Exception('x'));
