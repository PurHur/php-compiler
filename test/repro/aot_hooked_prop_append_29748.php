<?php
class C {
  public array $prop {
    get { echo "GET\n"; return $this->prop ??= []; }
    set { echo "SET\n"; $this->prop = $value; }
  }
}
$o = new C();
try {
  $o->prop[] = 1;
  echo "ok\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
