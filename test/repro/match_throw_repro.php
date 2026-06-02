<?php
try {
    echo match (0) {
        0 => throw new Exception(),
        default => 'd',
    };
} catch (Exception $e) {
    echo "caught\n";
}
