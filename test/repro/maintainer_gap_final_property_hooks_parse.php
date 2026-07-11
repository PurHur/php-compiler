<?php
class C {
    public final string $label {
        get => 'ok';
    }
}
$c = new C();
echo $c->label;
