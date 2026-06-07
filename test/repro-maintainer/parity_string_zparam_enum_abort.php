<?php
enum ES: string { case X = 'x'; }
enum EI: int { case A = 1; }

$caught = 0;

try {
    count_chars(ES::X);
} catch (TypeError $e) {
    echo 'count_chars_string_operand: ', $e->getMessage(), "\n";
    ++$caught;
}

try {
    chunk_split('abc', EI::A);
} catch (TypeError $e) {
    echo 'chunk_split_length_operand: ', $e->getMessage(), "\n";
    ++$caught;
}

echo "caught={$caught}\n";
