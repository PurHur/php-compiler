<?php
// Maintainer repro for #5237 — RuntimeException throw + catch (ext/spl/spl_exceptions.c).
try {
    throw new RuntimeException('boom');
} catch (RuntimeException $e) {
    echo 'caught:', get_class($e), "\n";
}
