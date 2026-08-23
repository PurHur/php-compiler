<?php
/**
 * #34084 — `$obj->$m()` after `$m = 'literal'` must compile and run under AOT.
 *
 * Expect (Zend): 7\nok
 */
class C
{
    public function foo(): int
    {
        return 7;
    }
}

$o = new C();
$m = 'foo';
echo $o->$m(), "\n";
echo 'ok';
