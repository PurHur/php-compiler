<?php
class Ex {}

try {
    try {
        throw new Ex();
    } catch (Ex $e) {
        throw;
    }
} catch (Ex $e) {
    echo "ok\n";
}
