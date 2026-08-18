<?php
// AOT lint: is_uploaded_file() named filename: (#28853). Runtime is covered by
// test/fixtures/aot/cases/is_uploaded_file_named_28853.phpt and the issue repro.
is_uploaded_file(filename: '/nope');
