--TEST--
Language: enum case import JIT (#6219)
--FILE--
<?php
namespace App;

enum Status: string {
    case Ok = 'ok';
}

use Status\Ok;

echo Ok->name, "\n";
?>
--EXPECT--
Ok
