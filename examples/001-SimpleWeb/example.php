<?php

declare(strict_types=1);

/**
 * Minimal web-style page: reads ?name= from $_GET and prints HTML.
 * Run with: QUERY_STRING='name=World' php bin/vm.php examples/001-SimpleWeb/example.php
 * Or: php bin/vm.php -q 'name=World' examples/001-SimpleWeb/example.php
 */
if (isset($_GET['name'])) {
    $name = $_GET['name'];
} else {
    $name = 'Guest';
}
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello ', $name, "</h1>\n";
echo '</body></html>';
