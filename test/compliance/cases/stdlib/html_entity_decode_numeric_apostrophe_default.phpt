--TEST--
stdlib html_entity_decode() default decodes numeric &#039; (#15275, ext/standard/html.c)
--FILE--
<?php
echo html_entity_decode('&lt;&#039;&gt;'), "\n";
echo html_entity_decode('&lt;&#039;&gt;', ENT_COMPAT), "\n";
--EXPECT--
<'>
<&#039;>
