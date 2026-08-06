<?php
// Issue #27182 — AOT json_encode(array_chunk(lit)) must match Zend nested chunks.
echo json_encode(array_chunk([1, 2, 3, 4, 5], 2)), "\n";
