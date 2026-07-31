--TEST--
Language: enum method ?self / self|null return accepts case (zend_execute.c, #25947)
--FILE--
<?php
enum E: int {
    case A = 1;
    public static function plain(): self { return self::A; }
    public static function nullable(): ?self { return self::A; }
    public static function union(): self|null { return self::A; }
    public static function nullOnly(): ?self { return null; }
}
class C {
    public function nullable(): ?self { return $this; }
}
echo "plain=", E::plain()->value, "\n";
echo "nullable=", E::nullable()->value, "\n";
echo "union=", E::union()->value, "\n";
echo "nullOnly=", var_export(E::nullOnly(), true), "\n";
echo "class=", (new C())->nullable() instanceof C ? "1" : "0", "\n";
--EXPECT--
plain=1
nullable=1
union=1
nullOnly=NULL
class=1
