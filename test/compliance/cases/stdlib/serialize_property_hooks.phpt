--TEST--
stdlib serialize()/unserialize() on objects with property hooks (#28184, re-#6474, ext/standard/var.c)
--FILE--
<?php
// Virtual hook only — Zend omits (no backing slot).
class H {
    public string $x {
        get => 'g';
        set { }
    }
}
echo serialize(new H), "\n";

// Distinct private backing — serialize mangled backing, not public hook name.
class C {
    private string $x = 'secret';
    public string $y { get => $this->x; set => $this->x = $value; }
}
$c = new C();
$s = serialize($c);
echo str_replace("\0", '\\0', $s), "\n";
$u = unserialize($s);
var_export($u instanceof C);
echo "\n";
var_export($u->y);
echo "\n";

// Plain + virtual hook — only plain in payload.
class P {
    public string $plain = 'p';
    public string $x {
        get => 'g';
        set { }
    }
}
echo serialize(new P), "\n";

// json_encode still uses get hooks (contrast with serialize).
echo json_encode(new H), "\n";
--EXPECT--
O:1:"H":0:{}
O:1:"C":1:{s:4:"\0C\0x";s:6:"secret";}
true
'secret'
O:1:"P":1:{s:5:"plain";s:1:"p";}
{"x":"g"}
