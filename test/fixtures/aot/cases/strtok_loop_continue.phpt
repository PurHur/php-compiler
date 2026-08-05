--TEST--
AOT: strtok continue in loop advances state (#27645)
--FILE--
<?php
$t = strtok("a b c", " ");
while ($t !== false) {
    echo "[$t]";
    $t = strtok(" ");
}
echo "\nDONE\n";
--EXPECT--
[a][b][c]
DONE
