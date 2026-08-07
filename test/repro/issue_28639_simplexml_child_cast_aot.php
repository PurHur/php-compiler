<?php
// AOT: (string)$sxe->child must match VM (re-#26863 / #28639).
$x = simplexml_load_string("<r><a>1</a><b>2</b></r>");
echo (string)$x->a, ",", (string)$x->b, "\n";
$y = $x->a;
echo (string)$y, "\n";
