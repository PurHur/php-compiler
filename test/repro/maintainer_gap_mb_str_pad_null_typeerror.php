<?php
declare(strict_types=1);

$threw = false;
try {
    mb_str_pad(null, 5);
} catch (TypeError $e) {
    $threw = true;
    $msg = $e->getMessage();
}
echo $threw ? 'ok:TypeError' : 'bad:no_throw';
if ($threw) {
    echo "\n" . $msg . "\n";
}
