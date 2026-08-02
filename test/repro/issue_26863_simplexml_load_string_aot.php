<?php
$x = simplexml_load_string("<root><a>1</a><a>2</a></root>");
echo (string)$x->a[0], ",", (string)$x->a[1], "\n";
echo count($x->a), "\n";
