--TEST--
Language: method call false return in concat operand — var_export($obj->m(), true) (#17767, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function f(): bool {
        return false;
    }
}

$c = new C();
echo 'x='.var_export($c->f(), true)."\n";

function g(): bool {
    return false;
}
echo 'z='.var_export(g(), true)."\n";

function gen(): Generator {
    yield 1;
    return 99;
}
$g = gen();
$g->next();
$g->next();
echo 'valid='.var_export($g->valid(), true)."\n";

$tmp = $c->f();
echo 'assign='.var_export($tmp, true)."\n";
--EXPECT--
x=false
z=false
valid=false
assign=false
