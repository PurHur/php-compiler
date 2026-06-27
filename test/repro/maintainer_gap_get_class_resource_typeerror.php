<?php

declare(strict_types=1);

$stream = fopen('php://memory', 'r+');
$ok = true;

try {
    get_class($stream);
    echo "fail: get_class(stream) succeeded\n";
    $ok = false;
} catch (TypeError $e) {
    echo 'get_class: ', $e->getMessage(), "\n";
}

echo 'class_exists Resource: ', (class_exists('Resource', false) ? 'yes' : 'no'), "\n";
echo 'instanceof Resource: ', ($stream instanceof Resource ? 'yes' : 'no'), "\n";

exit($ok ? 0 : 1);
