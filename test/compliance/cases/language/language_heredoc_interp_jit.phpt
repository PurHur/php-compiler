--TEST--
Language: heredoc with interpolation JIT (issue #178)
--FILE--
<?php
$name = 'Dev';
echo <<<EOT
Hello, {$name}

EOT;

$route = '/api';
echo <<<HTML
Route: {$route}

HTML;
--EXPECT--
Hello, Dev
Route: /api
