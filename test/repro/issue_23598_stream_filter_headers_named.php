<?php
declare(strict_types=1);

foreach (['stream_select', 'filter_var_array', 'get_headers'] as $f) {
    $rf = new ReflectionFunction($f);
    $n = [];
    foreach ($rf->getParameters() as $p) {
        $x = $p->getName();
        if ($p->isPassedByReference()) {
            $x = '&'.$x;
        }
        $n[] = $x;
    }
    echo $f, ': ', implode(',', $n), PHP_EOL;
}
try {
    var_export(filter_var_array(array: ['a' => '1'], options: ['a' => FILTER_VALIDATE_INT]));
    echo PHP_EOL;
} catch (Throwable $t) {
    echo 'fva: ', $t->getMessage(), PHP_EOL;
}
try {
    @get_headers(url: 'http://127.0.0.1:1/', associative: true);
    echo "headers_ok\n";
} catch (Throwable $t) {
    echo 'headers: ', $t->getMessage(), PHP_EOL;
}
