<?php

declare(strict_types=1);

var_dump(class_exists('RequestParseBodyException'));
var_dump(is_subclass_of('RequestParseBodyException', 'Exception'));
var_dump(is_a('RequestParseBodyException', 'Throwable', true));

try {
    throw new RequestParseBodyException('malformed body');
} catch (RequestParseBodyException $e) {
    echo 'caught:', $e->getMessage(), "\n";
} catch (Exception $e) {
    echo 'parent_catch:', $e->getMessage(), "\n";
}
