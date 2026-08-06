--TEST--
stdlib range() INF/-INF bounds ValueError like Zend (#27927, ext/standard/array.c)
--FILE--
<?php
foreach ([[0, INF], [INF, 0], [0, -INF], [1.5, INF]] as $args) {
    try {
        $r = range($args[0], $args[1]);
        echo 'ok:', count($r), "\n";
    } catch (ValueError $e) {
        echo 'ValueError:', $e->getMessage(), "\n";
    }
}
echo 'finite:', implode(',', range(1, 3)), "\n";
?>
--EXPECT--
ValueError:Invalid range supplied: start=0 end=inf
ValueError:Invalid range supplied: start=inf end=0
ValueError:Invalid range supplied: start=0 end=inf
ValueError:Invalid range supplied: start=2 end=inf
finite:1,2,3
