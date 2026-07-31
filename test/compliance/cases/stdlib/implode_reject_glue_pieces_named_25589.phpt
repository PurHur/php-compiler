--TEST--
stdlib implode/join reject named glue/pieces (#25589, ext/standard/basic_functions.stub.php)
--FILE--
<?php
foreach (['implode', 'join'] as $fn) {
    foreach (['glue', 'pieces', 'separator'] as $name) {
        try {
            if ($name === 'glue' || $name === 'separator') {
                $args = [$name => ',', 'array' => [1, 2]];
            } else {
                $args = ['separator' => ',', $name => [1, 2]];
            }
            $r = $fn(...$args);
            echo "$fn $name=";
            var_export($r);
            echo "\n";
        } catch (Throwable $e) {
            echo $fn, ' ', $name, '=', get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
?>
--EXPECT--
implode glue=Error: Unknown named parameter $glue
implode pieces=Error: Unknown named parameter $pieces
implode separator='1,2'
join glue=Error: Unknown named parameter $glue
join pieces=Error: Unknown named parameter $pieces
join separator='1,2'
