<?php
class Foo {
    public mixed $data {
        get { echo "get\n"; return $this->data; }
    }
}
$foo = new Foo();
echo isset($foo->data) ? "isset=true\n" : "isset=false\n";
