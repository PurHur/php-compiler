<?php
try {
  $f = function () {};
  $f->a = 1;
} catch (Error $e) {
  var_export($e->getFile()); echo "\n";
  var_export($e->getLine()); echo "\n";
  echo $e->getMessage(), "\n";
}
