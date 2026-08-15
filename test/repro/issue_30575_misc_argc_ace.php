<?php
// Repro #30575 — excess argc must be ArgumentCountError with Zend wording.
$cases = [
    'preg_split("/,/", "a,b", -1, 0, "x")',
    'spl_autoload_register(fn() => null, true, true, "x")',
    'iterator_to_array(new ArrayIterator([1]), true, "x")',
    'iterator_count(new ArrayIterator([1]), "x")',
    'get_mangled_object_vars(new stdClass, "x")',
];
foreach ($cases as $code) {
    try {
        eval($code . ';');
        echo "NO_THROW\n";
    } catch (Throwable $e) {
        echo $code, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
