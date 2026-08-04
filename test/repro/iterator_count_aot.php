<?php
echo iterator_count(new ArrayIterator([1, 2, 3])), "\n";
try {
    echo iterator_count(null);
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo iterator_count([1, 2, 3]), "\n";
