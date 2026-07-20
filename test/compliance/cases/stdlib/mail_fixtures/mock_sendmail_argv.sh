#!/bin/sh
# Record argv for mail() additional_params / force_extra_parameters (#21434).
dir="$(dirname "$0")"
printf '%s\n' "$*" > "$dir/mock_sendmail_argv.last"
cat > "$dir/mock_sendmail.last"
exit 0
