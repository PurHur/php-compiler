<?php

declare(strict_types=1);

echo pathinfo('/foo/bar/baz.txt', PATHINFO_FILENAME), "\n";
echo pathinfo('/foo/bar/baz.txt', PATHINFO_EXTENSION), "\n";
echo pathinfo('/foo/bar/baz.txt', PATHINFO_BASENAME), "\n";
echo pathinfo('/foo/bar/baz.txt', flags: PATHINFO_FILENAME), "\n";
echo pathinfo('/foo/bar/baz.txt', flags: PATHINFO_EXTENSION), "\n";
echo pathinfo('/foo/bar/baz.txt', flags: PATHINFO_BASENAME), "\n";
