<?php

$name = (string) ($_REQUEST['name'] ?? 'World');
echo '<h1>Hello ', htmlspecialchars($name), '</h1>', "\n";
echo '<p>Parity with <a href="/index.php/hello?name=World">001-SimpleWeb</a> greet route.</p>', "\n";
