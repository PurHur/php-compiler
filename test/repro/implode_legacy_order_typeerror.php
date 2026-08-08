<?php
try {
    implode(['a', 'b'], '-');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
