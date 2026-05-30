<?php
class Ex {
    public string $message = 'inner';
}

try {
    try {
        throw new Ex();
    } catch (Ex $e) {
        throw;
    }
} catch (Ex $e) {
    echo $e->message, "\n";
}
