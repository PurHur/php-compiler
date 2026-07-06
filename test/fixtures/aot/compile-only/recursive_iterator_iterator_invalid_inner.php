<?php
// AOT compile-only (#16917): RecursiveIteratorIterator rejects non-recursive inner at runtime.
new RecursiveIteratorIterator(new ArrayIterator([1]));
