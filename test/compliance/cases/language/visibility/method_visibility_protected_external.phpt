--TEST--
protected method rejected from global scope
--FILE--
<?php
class C {
    protected function hidden(): void {
        echo "no\n";
    }
}

$c = new C();
$c->hidden();
--EXPECTREGEX--
Call to protected method C::hidden\(\)
