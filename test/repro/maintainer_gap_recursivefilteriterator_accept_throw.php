<?php
class RF extends RecursiveFilterIterator {
  public function accept(): bool { throw new Exception('rej'); }
}
$it = new RF(new RecursiveArrayIterator([1, [2, 3]]));
try {
  foreach ($it as $v) { echo "v="; var_export($v); echo "\n"; }
} catch (Exception $e) {
  echo 'Exception: ', $e->getMessage(), "\n";
}
