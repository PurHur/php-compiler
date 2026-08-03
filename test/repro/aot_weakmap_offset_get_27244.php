<?php
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 42;
echo $wm[$o], "\n";
