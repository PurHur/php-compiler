<?php
class C { public private(set) string $x = "a"; }
$c = new C();
echo "read=", $c->x, "\n";
try {
  $c->x = "b";
  echo "wrote=", $c->x, "\n";
} catch (Throwable $e) {
  echo "catch=", get_class($e), ":", $e->getMessage(), "\n";
}
echo "done\n";
