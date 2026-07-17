<?php
// AOT ClassEntry smoke (#20136) — full children()/foreach AOT needs simplexml runtime lowering
echo method_exists(SimpleXMLElement::class, 'children') ? 'true' : 'false', "\n";
echo method_exists(SimpleXMLElement::class, 'key') ? 'true' : 'false', "\n";
echo method_exists(SimpleXMLElement::class, 'getName') ? 'true' : 'false', "\n";
