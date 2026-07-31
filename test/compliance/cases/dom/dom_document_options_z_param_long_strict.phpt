--TEST--
DOMDocument::loadHTML options strict_types TypeError (#25768)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
try {
    $doc->loadHTML('<p>x</p>', '0');
    echo "fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'must be of type int, string given') ? 'strict=te' : 'strict=bad'), "\n";
}
echo ($doc->loadHTML('<p>x</p>', 0) ? 'int=ok' : 'int=bad'), "\n";
--EXPECT--
strict=te
int=ok
