<?php
class F extends FilterIterator {
  public function accept(): bool { throw new Exception('no'); }
}
$it = new F(new ArrayIterator([1,2]));
try {
  foreach ($it as $v) { echo "v=$v\n"; }
} catch (Exception $e) {
  echo 'Exception: ', $e->getMessage(), "\n";
}
