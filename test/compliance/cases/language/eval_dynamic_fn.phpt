--TEST--
language eval() dynamic $code in user function — VM lowering when arg is not literal (#10248)
--FILE--
<?php
function run($code) {
    $r = eval($code);
    var_dump($r);
}
run('return 5;');
--EXPECT--
int(5)
