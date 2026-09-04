<?php
// @differential-repeat: 3 nested FuncCall + ArrayDimFetch call-arg (#36380)
function show($a, $b) {
    echo json_encode($a), '|', json_encode($b), "\n";
}
function id($x) { return $x; }
$t = 'hello';
show(id($t), $t[0]);
show(chop($t, 'o'), $t[0]);
$Line = ['text' => '- li'];
echo chop(chop($Line['text'], ' '), $Line['text'][0]) === '' ? "setext\n" : "list\n";
