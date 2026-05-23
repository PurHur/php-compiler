--TEST--
Language: heredoc with interpolation (issue #178)
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

$user = ['id' => 42];
echo <<<EOT
id={$user['id']}

EOT;
--EXPECT--
Hello, Dev
Route: /api
id=42
