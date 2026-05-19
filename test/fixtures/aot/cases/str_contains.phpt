--TEST--
AOT: str_contains() for request path / user-agent style checks
--FILE--
<?php
$path = '/api/v1/users';
echo str_contains($path, '/api/') ? 'api' : 'no', "\n";
echo str_contains($path, 'users') ? 'users' : 'no', "\n";
echo str_contains($path, 'admin') ? 'admin' : 'no', "\n";
--EXPECT--
api
users
no
