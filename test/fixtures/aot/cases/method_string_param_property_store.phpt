--TEST--
AOT: $this->prop = $v inside method persists typed string parameter (#24723)
--FILE--
<?php
class C {
    public string $name = "DEFAULT";
    public function setName(string $v): void { $this->name = $v; }
    public function getName(): string { return $this->name; }
}
$o = new C;
echo $o->getName(), "\n";
$o->setName("hi");
echo $o->getName(), "\n";
echo $o->name, "\n";
--EXPECT--
DEFAULT
hi
hi
--EXPECT_EXIT--
0
