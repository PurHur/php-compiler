--TEST--
Bare $this as call argument is object without prior $this-> touch (issue #28049)
--FILE--
<?php
class Demo {
  public $u = 3;
  public function onlyIsObject() {
    echo is_object($this) ? "y" : "n", "\n";
  }
  public function onlyGettype() {
    echo gettype($this), "\n";
  }
  public function onlyGov() {
    try {
      echo implode(",", array_keys(get_object_vars($this))), "\n";
    } catch (Throwable $e) {
      echo get_class($e), "\n";
    }
  }
  public function assignFirst() {
    $t = $this;
    echo gettype($t), "\n";
  }
  public function propThenArg() {
    $x = $this->u;
    echo gettype($this), "\n";
  }
}
(new Demo())->onlyIsObject();
(new Demo())->onlyGettype();
(new Demo())->onlyGov();
(new Demo())->assignFirst();
(new Demo())->propThenArg();
--EXPECT--
y
object
u
object
object
