--TEST--
Language: array dim with comma-for-init index compiles — not false empty-offset read (#1492, regression from #12303)
--FILE--
<?php
function setExtendedParentPointers(array &$array): void
{
    $length = count($array);
    $array[0] += $array[1];
    for ($headNode = 0, $tailNode = 1, $topNode = 2; $tailNode < ($length - 1); ++$tailNode) {
        if ($topNode >= $length || $array[$headNode] < $array[$topNode]) {
            $temp = $array[$headNode];
            $array[$headNode++] = $tailNode;
        } else {
            $temp = $array[$topNode++];
        }
        if ($topNode >= $length || ($headNode < $tailNode && $array[$headNode] < $array[$topNode])) {
            $temp += $array[$headNode];
            $array[$headNode++] = $tailNode + $length;
        } else {
            $temp += $array[$topNode++];
        }
        $array[$tailNode] = $temp;
    }
}
echo "ok\n";
--EXPECT--
ok
