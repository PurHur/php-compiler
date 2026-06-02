--TEST--
Generator iterator protocol starts on current/key/valid (issue #4432)
--FILE--
<?php
function gen() {
    yield 10;
    yield 20;
    return 99;
}

$g = gen();
var_dump($g instanceof Iterator);
var_dump($g->current());
var_dump($g->next());
var_dump($g->current());
$g->next();
var_dump($g->valid());
var_dump($g->current());

function deleg() {
    yield from ["a" => 1, "b" => 2];
}
var_dump(iterator_to_array(deleg()));
--EXPECT--
bool(true)
int(10)
NULL
int(20)
bool(false)
NULL
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
}

