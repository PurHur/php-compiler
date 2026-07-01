<?php
// Repro for #14334 — never in union/intersection signatures must compile-fatal (php-src-strict).
function bad(): int|never {
    throw new Exception('unreachable');
}
