<?php
try {
    try {
        throw new Exception('x');
    } catch (Exception $e) {
        echo 'inner\n';
        throw $e;
    }
} catch (Exception $e) {
    echo 'outer:', $e->getMessage(), "\n";
}
