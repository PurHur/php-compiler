<?php
// Issue #30435 — ??= on array elements with echo concat
$arr = [];
$arr["x"] ??= "new";
echo "1: " . $arr["x"] . "\n";

$arr2 = ["y" => null];
$arr2["y"] ??= "override";
echo "2: " . $arr2["y"] . "\n";

$arr3 = ["z" => "existing"];
$arr3["z"] ??= "nope";
echo "3: " . $arr3["z"] . "\n";

$arr4 = [];
$arr4["a"]["b"] ??= "c";
echo "4: " . $arr4["a"]["b"] . "\n";
