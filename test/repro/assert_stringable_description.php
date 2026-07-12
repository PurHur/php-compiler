<?php

declare(strict_types=1);

ini_set('assert.active', '1');
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');

class Msg
{
    public function __toString(): string
    {
        return 'x';
    }
}

foreach (['inline', 'stored'] as $mode) {
    try {
        if ('inline' === $mode) {
            assert(false, new Msg());
        } else {
            $m = new Msg();
            assert(false, $m);
        }
        echo $mode, '=no_error', "\n";
    } catch (TypeError $e) {
        echo $mode, '=TypeError', "\n";
    } catch (AssertionError $e) {
        echo $mode, '=AssertionError', "\n";
    }
}

try {
    assert(false, 'msg');
    echo 'string=no_error', "\n";
} catch (AssertionError $e) {
    echo 'string=AssertionError:', $e->getMessage(), "\n";
}
