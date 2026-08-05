<?php

// Repro for #27519 — AOT array_replace(null, …) TypeError (not compile abort)
try {
    array_replace(null, [1]);
    echo "no throw\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
