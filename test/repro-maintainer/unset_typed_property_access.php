<?php
declare(strict_types=1);
// test/repro-maintainer/unset_typed_property_access.php

class T { public int $i = 0; }
$t = new T();
unset($t->i);
try {
    echo "got=", $t->i, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
