<?php
function fail(): never {
    throw new Exception('x');
}
try {
    fail();
    echo "after\n";
} catch (Exception $e) {
    echo "caught\n";
}
