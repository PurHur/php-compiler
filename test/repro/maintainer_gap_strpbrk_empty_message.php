<?php
try {
    strpbrk('hello', '');
} catch (\ValueError $e) {
    echo $e->getMessage(), "\n";
}
