<?php
require __DIR__.'/../fixtures/aot/projects/slim_hello_36382/vendor/autoload.php';
$r = new Nyholm\Psr7\Response(200);
$b = $r->getBody();
$b->write('hello');
echo (string)$b, "\n";
echo 'status=', $r->getStatusCode(), "\n";
