<?php

/**
 * Repro for #24893 — new TraitName() must throw Error (zend_API.c).
 *
 * Run: php bin/vm.php test/repro/issue_24893_cannot_instantiate_trait.php
 */
trait T {}

try {
    $t = new T();
    echo "ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
