<?php
/**
 * #24447 — var_export ArrayIterator/ArrayObject must include storage in __set_state.
 */
echo var_export(new ArrayIterator([0 => 1, 'x' => 2]), true), "\n";
echo var_export(new ArrayObject([0 => 1, 'x' => 2]), true), "\n";
