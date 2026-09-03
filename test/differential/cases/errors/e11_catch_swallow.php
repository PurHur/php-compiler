<?php
try {
    throw new Exception('x');
} catch (Exception $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
echo "after\n";
