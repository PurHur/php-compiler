--TEST--
AOT: html_entity_decode() default decodes numeric &#039; (#15275)
--FILE--
<?php
echo html_entity_decode('&lt;&#039;&gt;'), "\n";
--EXPECT--
<'>
