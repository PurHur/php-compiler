<?php
class C {
    public string $greeting {
        get => 'hello';
    }
}
$c = new C();
echo $c->greeting, "\n";
