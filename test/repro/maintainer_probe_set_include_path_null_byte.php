<?php

try {
    set_include_path("\x00");
    echo "no error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
