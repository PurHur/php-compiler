--TEST--
intl collator_compare/getSortKey(null) TypeError on 8.4 forward (#21077)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!function_exists('collator_create') || !class_exists('Collator', false)) {
    die("skip ext/intl Collator not available");
}
$c = collator_create('en');
foreach ([
    'proc_compare' => static fn () => collator_compare($c, null, 'a'),
    'method_compare' => static fn () => (new Collator('en'))->compare(null, 'a'),
    'proc_sort_key' => static fn () => collator_get_sort_key($c, null),
    'method_sort_key' => static fn () => (new Collator('en'))->getSortKey(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError';
        if (false !== strpos($e->getMessage(), 'null given')) {
            echo ' null';
        }
        echo "\n";
    }
}
echo 'empty_compare=', (int) (0 === collator_compare($c, '', '')), "\n";
$sk = collator_get_sort_key($c, '');
echo 'empty_sort_key=', (int) (is_string($sk) && '' !== $sk), "\n";
?>
--EXPECT--
proc_compare TypeError null
method_compare TypeError null
proc_sort_key TypeError null
method_sort_key TypeError null
empty_compare=1
empty_sort_key=1
