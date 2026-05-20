<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
$name = isset($_GET['name']) ? $_GET['name'] : 'World';
echo 'Hello ', htmlspecialchars($name);
