<?php
function boom() {
    throw new Exception('fn');
}
try {
    boom();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
