<?php
// #35368 — ClassConstFetch must seed DateTimeInterface::* for thin AOT (peer #35360).
echo DateTimeInterface::ATOM, '|';
echo DateTimeInterface::COOKIE, '|';
echo DateTimeInterface::RFC3339, "\n";
