<?php $a=["k"=>null]; echo $a["k"] ?? "dflt", "\n"; function f($p,$q){echo "$p/$q\n";} f($a["k"] ?? "A", $a["nope"] ?? "B");
