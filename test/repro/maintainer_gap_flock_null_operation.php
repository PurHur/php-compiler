<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "fail:no_throw\n";
} catch (ValueError $e) {
    echo 'ok:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fail:', get_class($e), ':', $e->getMessage(), "\n";
} finally {
    fclose($fp);
}
