--TEST--
stdlib uniqid() more_entropy — 23-char string per Zend (ext/standard/uniqid.c, #10670)
--FILE--
<?php
for ($i = 0; $i < 3; $i++) {
    echo strlen(uniqid('', true)), "\n";
}
--EXPECT--
23
23
23
