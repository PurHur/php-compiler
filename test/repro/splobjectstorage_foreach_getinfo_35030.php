<?php
$s = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$s[$a] = "a";
$s[$b] = "b";
foreach ($s as $obj) {
  echo $s->getInfo(), "\n";
}
