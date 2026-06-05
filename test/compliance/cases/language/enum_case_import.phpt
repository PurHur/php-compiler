--TEST--
Language: enum case import (use Status\CaseName) (#6219)
--FILE--
<?php
namespace App;

enum Status: string {
    case Ok = 'ok';
    case Err = 'err';
}

use Status\Ok;

function label(): string {
    return Ok->name;
}

echo label(), "\n";
?>
--EXPECT--
Ok
