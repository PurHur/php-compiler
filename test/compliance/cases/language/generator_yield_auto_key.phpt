--TEST--
Generator bare yield auto-key continues after explicit numeric keys (#22343)
--FILE--
<?php
function keyed_then_bare() {
    yield 5 => 'x';
    yield;
}
foreach (keyed_then_bare() as $k => $v) {
    echo $k, '=', var_export($v, true), "\n";
}

function lower_then_higher() {
    yield 5 => 'a';
    yield 3 => 'b';
    yield;
}
foreach (lower_then_higher() as $k => $v) {
    echo $k, '=', var_export($v, true), "\n";
}

function send_after_explicit() {
    yield 10 => 'first';
    yield;
}
$gen = send_after_explicit();
echo 'send0=', $gen->key(), "\n";
$gen->send('ignored');
echo 'send1=', var_export($gen->key(), true), "\n";

// yield from does not bump outer auto-key (zend_generators.c)
function from_then_bare() {
    yield from [7 => 'a', 8 => 'b'];
    yield;
}
foreach (from_then_bare() as $k => $v) {
    echo $k, '=', var_export($v, true), "\n";
}
--EXPECT--
5='x'
6=NULL
5='a'
3='b'
6=NULL
send0=10
send1=11
7='a'
8='b'
0=NULL
