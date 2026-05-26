/*
 * htmlspecialchars_decode() runtime for VM/JIT/AOT (issue #2454).
 * UTF-8 byte subset mirroring VmString::htmlspecialchars_decode (ENT_QUOTES / ENT_COMPAT).
 */

#include <stddef.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static int phpc_hdec_quote_both(int64_t flags)
{
    return 0 != (flags & 3);
}

static int phpc_hdec_quote_double(int64_t flags)
{
    return !phpc_hdec_quote_both(flags) && (0 != (flags & 2));
}

static int phpc_hdec_match(const char *s, const char *entity)
{
    return 0 == strncmp(s, entity, strlen(entity));
}

__string__ *phpc_htmlspecialchars_decode(const char *str, int64_t flags)
{
    const int quote_both = phpc_hdec_quote_both(flags);
    const int quote_double = phpc_hdec_quote_double(flags);
    size_t in_len;
    size_t out_pos = 0;
    char *out;
    size_t i;

    if (NULL == str) {
        str = "";
    }
    in_len = strlen(str);
    if (0 == in_len) {
        return __string__init(0, "");
    }

    out = (char *) malloc(in_len);
    if (NULL == out) {
        return __string__init(0, "");
    }

    for (i = 0; i < in_len;) {
        if ('&' != str[i]) {
            out[out_pos++] = str[i++];
            continue;
        }
        if (i + 5 <= in_len && phpc_hdec_match(str + i, "&amp;")) {
            out[out_pos++] = '&';
            i += 5;
            continue;
        }
        if (i + 4 <= in_len && phpc_hdec_match(str + i, "&lt;")) {
            out[out_pos++] = '<';
            i += 4;
            continue;
        }
        if (i + 4 <= in_len && phpc_hdec_match(str + i, "&gt;")) {
            out[out_pos++] = '>';
            i += 4;
            continue;
        }
        if ((quote_both || quote_double) && i + 6 <= in_len && phpc_hdec_match(str + i, "&quot;")) {
            out[out_pos++] = '"';
            i += 6;
            continue;
        }
        if (quote_both && i + 6 <= in_len && phpc_hdec_match(str + i, "&#039;")) {
            out[out_pos++] = '\'';
            i += 6;
            continue;
        }
        out[out_pos++] = str[i++];
    }

    {
        __string__ *result = __string__init((long long) out_pos, out);
        free(out);

        return result;
    }
}
