<?php
// Issue #6457 — operand aliasing must not drop Next Exception on uncaught fatal.
try {
    throw new Exception('inner');
} finally {
    throw new Exception('finally');
}
