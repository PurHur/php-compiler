<?php
// repro #27237 — AOT wordwrap garble / cut_long abort under default helper-runtime cache
echo wordwrap('hello world foo', 5, '|'), "\n";
echo wordwrap('hello world foo', 5, '|', true), "\n";
echo wordwrap('verylongword', 5, '|', true), "\n";
