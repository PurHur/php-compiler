--TEST--
unserialize() constructor-promoted property round-trip (#13560, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);
class UnserializePromotedProbe {
    public function __construct(public string $v) {}
}
$s = serialize(new UnserializePromotedProbe('test'));
$o = unserialize($s);
echo $o->v, "\n";
?>
--EXPECT--
test
