<?php
// AOT lint: copy() named from:/to: (#23347). Runtime copy is covered by
// test/fixtures/aot/cases/copy_named_params.phpt and the issue repro.
copy(from: 'a', to: 'b');
