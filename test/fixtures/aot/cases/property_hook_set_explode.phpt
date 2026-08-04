--TEST--
AOT property hooks: set with explode matches Zend (#27660)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    echo "skip property hooks require PROFILE=8.4+";
}
--FILE--
<?php
class C {
  public string $full {
    get => $this->first . " " . $this->last;
    set (string $value) {
      [$this->first, $this->last] = explode(" ", $value, 2);
    }
  }
  public string $first = "A";
  public string $last = "B";
}
$c = new C;
echo $c->full, "\n";
$c->full = "X Y";
echo $c->first, "|", $c->last, "\n";
--EXPECT--
A B
X|Y
