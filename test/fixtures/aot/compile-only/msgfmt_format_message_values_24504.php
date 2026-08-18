<?php
// compile-only: named values: dispatch for msgfmt_format_message (#24504)
echo msgfmt_format_message(locale: 'en_US', pattern: 'Hi {0}', values: ['Ada']);
