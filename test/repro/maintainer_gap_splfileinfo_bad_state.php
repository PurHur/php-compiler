<?php
declare(strict_types=1);

$i = new SplFileInfo('/');
echo 'method_exists=', (int) method_exists($i, '_bad_state_ex'), "\n";
try {
    $i->_bad_state_ex();
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
