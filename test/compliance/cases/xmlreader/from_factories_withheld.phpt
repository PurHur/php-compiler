--TEST--
xmlreader XMLReader factories withheld on 8.2 reference profile (#19607)
--FILE--
<?php
try {
    XMLReader::fromString('<root/>');
    echo "called\n";
} catch (Error $e) {
    echo (str_contains($e->getMessage(), 'fromstring') || str_contains($e->getMessage(), 'fromString')) ? "absent\n" : ("other:".$e->getMessage()."\n");
}
?>
--EXPECT--
absent
