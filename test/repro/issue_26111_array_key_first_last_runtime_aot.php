<?php
/**
 * #26111 — AOT runtime for array_key_first / array_key_last (no Reflection).
 * Named array: + positional non-empty (empty→null AOT segfault is pre-existing).
 */
echo array_key_first(array: ['a' => 1, 'b' => 2]), "\n";
echo array_key_last(array: ['a' => 1, 'b' => 2]), "\n";
echo array_key_first(['x' => 10]), "\n";
echo array_key_last(['x' => 10, 'y' => 20]), "\n";
