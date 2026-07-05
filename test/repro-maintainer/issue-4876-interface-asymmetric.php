<?php
interface I {
    public (private(set)) string $slug;
}
class C implements I {
    public string $slug = 'b';
}
$c = new C();
try {
    $c->slug = 'x';
    echo "set ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
