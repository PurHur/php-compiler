--TEST--
Compound assignment on property hooks dispatches get/set hooks (#6427, zend_property_hooks.c)
--FILE--
<?php
class Counter {
    private int $n = 1;
    public int $count {
        get => $this->n;
        set (int $v) { $this->n = $v; }
    }
}
$c = new Counter();
$c->count += 2;
echo $c->count, "\n";
$c->count -= 1;
echo $c->count, "\n";

class Label {
    private string $v = 'a';
    public string $text {
        get => $this->v;
        set (string $s) { $this->v = $s; }
    }
}
$l = new Label();
$l->text .= 'b';
echo $l->text, "\n";

class Nullable {
    private ?int $v = null;
    public ?int $x {
        get => $this->v;
        set => $this->v = $value;
    }
}
$n = new Nullable();
$n->x ??= 5;
echo $n->x, "\n";
$n->x ??= 9;
echo $n->x, "\n";

class ReadOnlyHook {
    private int $n = 1;
    public int $count {
        get => $this->n;
    }
}
$r = new ReadOnlyHook();
try {
    $r->count += 1;
    echo "compound ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
3
2
ab
5
5
Error
