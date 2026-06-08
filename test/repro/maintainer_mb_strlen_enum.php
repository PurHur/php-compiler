<?php
enum Es: string { case B = 'hi'; }
try {
    echo mb_strlen(Es::B);
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage();
}
