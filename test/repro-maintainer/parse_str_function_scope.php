<?php
function t(): void {
    try {
        parse_str('a=1&b=2');
        echo "no throw\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
t();
