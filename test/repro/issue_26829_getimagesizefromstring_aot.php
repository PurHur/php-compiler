<?php
/**
 * Issue #26829: AOT getimagesizefromstring() 1×1 PNG must match Zend/VM/JIT.
 */
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$i = getimagesizefromstring($png);
if ($i === false) {
    echo "false\n";
    exit(0);
}
echo $i[0], 'x', $i[1], ' ', $i['mime'], "\n";
