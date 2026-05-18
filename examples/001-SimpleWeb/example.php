<?php

declare(strict_types=1);

/**
 * Minimal web-style page: reads ?name= from $_GET or POST body and prints HTML.
 * Run with: QUERY_STRING='name=World' php bin/vm.php examples/001-SimpleWeb/example.php
 * Or: php bin/vm.php -q 'name=World' examples/001-SimpleWeb/example.php
 * Or: php bin/vm.php -p 'name=World' examples/001-SimpleWeb/example.php
 */
if (isset($_GET['name'])) {
    $name = $_GET['name'];
} elseif (isset($_POST['name'])) {
    $name = $_POST['name'];
} else {
    $name = 'Guest';
}
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '</body></html>';
