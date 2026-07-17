<?php
$methods = ["beginChildren","endChildren","beginIteration","endIteration","nextElement"];
foreach ($methods as $m) {
  echo "$m=", method_exists("RecursiveTreeIterator", $m) ? "Y" : "N", "\n";
}
$it = new RecursiveTreeIterator(new RecursiveArrayIterator([1, [2, 3], 4]));
$seen = [];
foreach ($it as $k => $v) {
  $seen[] = $it->getEntry() . "@" . $it->getDepth();
}
echo "walk=", implode("|", $seen), "\n";
