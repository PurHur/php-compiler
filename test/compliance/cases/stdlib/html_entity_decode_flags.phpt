--TEST--
stdlib html_entity_decode() combined ENT_* flags (#10519)
--FILE--
<?php
declare(strict_types=1);
var_dump(html_entity_decode('&amp;', ENT_QUOTES | ENT_HTML5));
?>
--EXPECT--
string(1) "&"
