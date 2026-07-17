<?php
/**
 * #19784 — ParentIterator/RecursiveRegexIterator instanceof RecursiveIterator + RII.
 */
error_reporting(E_ALL);
$arr = new RecursiveArrayIterator(['a' => 1, 'b' => [2, 3], 'c' => 4]);
$pi = new ParentIterator($arr);
echo 'instanceof_RI=' . ($pi instanceof RecursiveIterator ? '1' : '0') . "\n";
echo 'implements=' . json_encode(class_implements($pi) ?: null) . "\n";
try {
    $it = new RecursiveIteratorIterator($pi, RecursiveIteratorIterator::SELF_FIRST);
    $out = [];
    foreach ($it as $k => $v) {
        $out[] = [$k, is_array($v) ? 'arr' : $v];
    }
    echo json_encode($out) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

$rx = new RecursiveRegexIterator(new RecursiveArrayIterator(['a' => 1, 'b' => [2]]), '/./');
echo 'rx_instanceof_RI=' . ($rx instanceof RecursiveIterator ? '1' : '0') . "\n";
echo 'rx_implements=' . json_encode(class_implements($rx) ?: null) . "\n";
try {
    new RecursiveIteratorIterator($rx);
    echo "rx_rii_ok\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
