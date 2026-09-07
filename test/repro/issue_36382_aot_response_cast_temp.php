<?php
require __DIR__."/../fixtures/aot/projects/slim_hello_36382/vendor/autoload.php";
$r = new Nyholm\Psr7\Response(200);
$r->getBody()->write("hello");
$body = $r->getBody();
echo (string)$body, "\n";
