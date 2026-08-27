--TEST--
AOT: json_encode(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) pretty-prints (#35339)
--FILE--
<?php
echo json_encode(['a' => 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
$f = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
echo json_encode(['a' => 1], $f), "\n";
?>
--EXPECT--
{
    "a": 1
}
{
    "a": 1
}
