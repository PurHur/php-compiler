--TEST--
ReflectionProperty::getType() declared type for hooked properties (#22481, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class GetOnly { public string $x { get => "g"; } }
class Backed {
  public string $x {
    get => $this->x;
    set(string $v) { $this->x = $v; }
  }
}
class Divergent {
  public string $x {
    get => "1";
    set(int $v) {}
  }
}
foreach ([GetOnly::class, Backed::class, Divergent::class] as $c) {
  $rp = new ReflectionProperty($c, "x");
  echo $c, "\tgetType=", $rp->hasType() ? (string)$rp->getType() : "none";
  echo "\tsettable=", (string)$rp->getSettableType(), "\n";
}
--EXPECT--
GetOnly	getType=string	settable=never
Backed	getType=string	settable=string
Divergent	getType=string	settable=int
