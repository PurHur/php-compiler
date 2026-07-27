<?php
function g(): Generator {
    $v = yield 1;
    yield $v;
}
$g = g();
$send = $g->send('x');
echo 'send=', var_export($send, true), ' cur=', var_export($g->current(), true), ' key=', var_export($g->key(), true), "\n";
