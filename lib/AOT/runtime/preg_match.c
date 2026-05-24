/*
 * preg_match() runtime for AOT/JIT (issue #93).
 * Uses libpcre2-8; patterns must use PHP delimiter syntax (#...#, /.../, etc.).
 * Returns match count (0 or 1) on success, -1 on compile/validation error.
 */

#define PCRE2_CODE_UNIT_WIDTH 8
#include <pcre2.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static size_t pm_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *pm_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

#define PM_MAX_PATTERN 4096

/* PHP PREG_* codes (subset); updated by __compiler_preg_match failures. */
#define PHPC_PREG_NO_ERROR 0
#define PHPC_PREG_BAD_REGEX 6

static int phpc_preg_last_error = PHPC_PREG_NO_ERROR;

static int pm_is_delim_invalid(char c)
{
    if (c >= 'a' && c <= 'z') {
        return 1;
    }
    if (c >= 'A' && c <= 'Z') {
        return 1;
    }
    if (c >= '0' && c <= '9') {
        return 1;
    }
    if (c == ' ' || c == '\t' || c == '\n' || c == '\r') {
        return 1;
    }
    if (c == '\\') {
        return 1;
    }

    return 0;
}

static int pm_parse_modifiers(const char *mods, size_t mod_len, uint32_t *opts)
{
    for (size_t i = 0; i < mod_len; i++) {
        switch (mods[i]) {
        case 'i':
            *opts |= PCRE2_CASELESS;
            break;
        case 'm':
            *opts |= PCRE2_MULTILINE;
            break;
        case 's':
            *opts |= PCRE2_DOTALL;
            break;
        default:
            return -1;
        }
    }

    return 0;
}

static int pm_extract_body(
    const char *full,
    size_t full_len,
    char *out,
    size_t out_cap,
    uint32_t *opts
)
{
    if (full_len < 2 || full_len > PM_MAX_PATTERN) {
        return -1;
    }
    char delim = full[0];
    if (pm_is_delim_invalid(delim)) {
        return -1;
    }
    size_t close = 0;
    for (size_t i = 1; i < full_len; i++) {
        if (full[i] == '\\') {
            i++;
            continue;
        }
        if (full[i] == delim) {
            close = i;
            break;
        }
    }
    if (0 == close || close + 1 > full_len) {
        return -1;
    }
    size_t body_len = close - 1;
    if (body_len + 1 > out_cap) {
        return -1;
    }
    memcpy(out, full + 1, body_len);
    out[body_len] = '\0';
    *opts = 0;

    return pm_parse_modifiers(full + close + 1, full_len - close - 1, opts);
}

int64_t __compiler_preg_last_error(void)
{
    return (int64_t) phpc_preg_last_error;
}

int64_t __compiler_preg_match(__string__ *pattern, __string__ *subject)
{
    const char *pat_full = pm_strdata(pattern);
    size_t pat_len = pm_strlen(pattern);
    const char *subj = pm_strdata(subject);
    size_t subj_len = pm_strlen(subject);

    phpc_preg_last_error = PHPC_PREG_NO_ERROR;

    if (pat_len > PM_MAX_PATTERN) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    char body[PM_MAX_PATTERN + 1];
    uint32_t opts = 0;
    if (0 != pm_extract_body(pat_full, pat_len, body, sizeof(body), &opts)) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code *re = pcre2_compile(
        (PCRE2_SPTR) body,
        PCRE2_ZERO_TERMINATED,
        opts,
        &errorcode,
        &erroffset,
        NULL
    );
    if (NULL == re) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    if (NULL == match_data) {
        pcre2_code_free(re);
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int rc = pcre2_match(
        re,
        (PCRE2_SPTR) subj,
        subj_len,
        0,
        0,
        match_data,
        NULL
    );

    pcre2_match_data_free(match_data);
    pcre2_code_free(re);

    if (rc < 0) {
        if (PCRE2_ERROR_NOMATCH == rc) {
            return 0;
        }

        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    return 1;
}

int64_t __compiler_preg_match_all(__string__ *pattern, __string__ *subject)
{
    const char *pat_full = pm_strdata(pattern);
    size_t pat_len = pm_strlen(pattern);
    const char *subj = pm_strdata(subject);
    size_t subj_len = pm_strlen(subject);

    phpc_preg_last_error = PHPC_PREG_NO_ERROR;

    if (pat_len > PM_MAX_PATTERN) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    char body[PM_MAX_PATTERN + 1];
    uint32_t opts = 0;
    if (0 != pm_extract_body(pat_full, pat_len, body, sizeof(body), &opts)) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code *re = pcre2_compile(
        (PCRE2_SPTR) body,
        PCRE2_ZERO_TERMINATED,
        opts,
        &errorcode,
        &erroffset,
        NULL
    );
    if (NULL == re) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    if (NULL == match_data) {
        pcre2_code_free(re);
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int64_t count = 0;
    PCRE2_SIZE start_offset = 0;

    while (start_offset <= subj_len) {
        int rc = pcre2_match(
            re,
            (PCRE2_SPTR) subj,
            subj_len,
            start_offset,
            0,
            match_data,
            NULL
        );

        if (rc < 0) {
            if (PCRE2_ERROR_NOMATCH == rc) {
                break;
            }

            pcre2_match_data_free(match_data);
            pcre2_code_free(re);
            phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

            return -1;
        }

        count++;

        PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
        PCRE2_SIZE match_end = ovector[1];
        if (match_end == start_offset) {
            start_offset++;
        } else {
            start_offset = match_end;
        }
    }

    pcre2_match_data_free(match_data);
    pcre2_code_free(re);

    return count;
}

static int pm_append(char **buf, size_t *cap, size_t *len, const char *data, size_t data_len)
{
    if (*len + data_len + 1 > *cap) {
        size_t need = *len + data_len + 1;
        size_t new_cap = (0 == *cap) ? 64 : *cap;
        while (new_cap < need) {
            new_cap *= 2;
        }
        char *grown = (char *) realloc(*buf, new_cap);
        if (NULL == grown) {
            return -1;
        }
        *buf = grown;
        *cap = new_cap;
    }
    memcpy(*buf + *len, data, data_len);
    *len += data_len;
    (*buf)[*len] = '\0';

    return 0;
}

__string__ *__compiler_preg_replace(__string__ *pattern, __string__ *replacement, __string__ *subject)
{
    const char *pat_full = pm_strdata(pattern);
    size_t pat_len = pm_strlen(pattern);
    const char *repl = pm_strdata(replacement);
    size_t repl_len = pm_strlen(replacement);
    const char *subj = pm_strdata(subject);
    size_t subj_len = pm_strlen(subject);

    phpc_preg_last_error = PHPC_PREG_NO_ERROR;

    if (pat_len > PM_MAX_PATTERN) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    char body[PM_MAX_PATTERN + 1];
    uint32_t opts = 0;
    if (0 != pm_extract_body(pat_full, pat_len, body, sizeof(body), &opts)) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code *re = pcre2_compile(
        (PCRE2_SPTR) body,
        PCRE2_ZERO_TERMINATED,
        opts,
        &errorcode,
        &erroffset,
        NULL
    );
    if (NULL == re) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    if (NULL == match_data) {
        pcre2_code_free(re);
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    char *out = NULL;
    size_t out_cap = 0;
    size_t out_len = 0;
    PCRE2_SIZE start = 0;

    while (start <= subj_len) {
        int rc = pcre2_match(
            re,
            (PCRE2_SPTR) subj,
            subj_len,
            start,
            0,
            match_data,
            NULL
        );

        if (PCRE2_ERROR_NOMATCH == rc) {
            if (0 != pm_append(&out, &out_cap, &out_len, subj + start, subj_len - start)) {
                goto fail;
            }
            break;
        }
        if (rc < 0) {
            goto fail;
        }

        PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
        if (0 != pm_append(&out, &out_cap, &out_len, subj + start, ovector[0] - start)) {
            goto fail;
        }
        if (0 != pm_append(&out, &out_cap, &out_len, repl, repl_len)) {
            goto fail;
        }
        if (ovector[1] == start) {
            start++;
        } else {
            start = ovector[1];
        }
    }

    pcre2_match_data_free(match_data);
    pcre2_code_free(re);

    if (NULL == out) {
        out = (char *) malloc(1);
        if (NULL == out) {
            phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

            return NULL;
        }
        out[0] = '\0';
        out_len = 0;
    }

    __string__ *result = __string__init((long long) out_len, out);
    free(out);

    return result;

fail:
    free(out);
    pcre2_match_data_free(match_data);
    pcre2_code_free(re);
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

    return NULL;
}

typedef struct __hashtable__ __hashtable__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);

static __string__ *pm_slice_to_string(const char *data, size_t len)
{
    char *buf;

    if (0 == len) {
        return __string__init(0, "");
    }
    buf = (char *) malloc(len + 1);
    if (NULL == buf) {
        return NULL;
    }
    memcpy(buf, data, len);
    buf[len] = '\0';
    {
        __string__ *result = __string__init((long long) len, buf);
        free(buf);

        return result;
    }
}

typedef struct __value__ {
    char type;
    char value[8];
} __value__;

extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern __string__ *__value__readString(__value__ *arg);

typedef __value__ *(*phpc_preg_replace_callback_fn)(__value__ *);

__string__ *__compiler_preg_replace_callback(
    __string__ *pattern,
    __string__ *subject,
    phpc_preg_replace_callback_fn cb
)
{
    const char *pat_full = pm_strdata(pattern);
    size_t pat_len = pm_strlen(pattern);
    const char *subj = pm_strdata(subject);
    size_t subj_len = pm_strlen(subject);

    phpc_preg_last_error = PHPC_PREG_NO_ERROR;

    if (NULL == cb) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    if (pat_len > PM_MAX_PATTERN) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    char body[PM_MAX_PATTERN + 1];
    uint32_t opts = 0;
    if (0 != pm_extract_body(pat_full, pat_len, body, sizeof(body), &opts)) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code *re = pcre2_compile(
        (PCRE2_SPTR) body,
        PCRE2_ZERO_TERMINATED,
        opts,
        &errorcode,
        &erroffset,
        NULL
    );
    if (NULL == re) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    if (NULL == match_data) {
        pcre2_code_free(re);
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    char *out = NULL;
    size_t out_cap = 0;
    size_t out_len = 0;
    PCRE2_SIZE start = 0;

    while (start <= subj_len) {
        int rc = pcre2_match(
            re,
            (PCRE2_SPTR) subj,
            subj_len,
            start,
            0,
            match_data,
            NULL
        );

        if (PCRE2_ERROR_NOMATCH == rc) {
            if (0 != pm_append(&out, &out_cap, &out_len, subj + start, subj_len - start)) {
                goto cb_fail;
            }
            break;
        }
        if (rc < 0) {
            goto cb_fail;
        }

        PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
        if (0 != pm_append(&out, &out_cap, &out_len, subj + start, ovector[0] - start)) {
            goto cb_fail;
        }

        __hashtable__ *matches = __hashtable__alloc();
        if (NULL == matches) {
            goto cb_fail;
        }
        for (int gi = 0; gi < rc; gi++) {
            if (ovector[2 * gi] == (PCRE2_SIZE) -1) {
                continue;
            }
            __string__ *piece = pm_slice_to_string(
                subj + ovector[2 * gi],
                (size_t) (ovector[2 * gi + 1] - ovector[2 * gi])
            );
            if (NULL == piece) {
                goto cb_fail;
            }
            __hashtable__setStringAt(matches, (size_t) gi, piece);
        }

        __value__ matches_box;
        __value__writeHashtable(&matches_box, matches);
        __value__ *repl_box = cb(&matches_box);
        if (NULL == repl_box) {
            goto cb_fail;
        }
        __string__ *repl = __value__readString(repl_box);
        if (NULL == repl) {
            goto cb_fail;
        }
        if (0 != pm_append(&out, &out_cap, &out_len, pm_strdata(repl), pm_strlen(repl))) {
            goto cb_fail;
        }

        if (ovector[1] == start) {
            start++;
        } else {
            start = ovector[1];
        }
    }

    pcre2_match_data_free(match_data);
    pcre2_code_free(re);

    if (NULL == out) {
        out = (char *) malloc(1);
        if (NULL == out) {
            phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

            return NULL;
        }
        out[0] = '\0';
        out_len = 0;
    }

    {
        __string__ *result = __string__init((long long) out_len, out);
        free(out);

        return result;
    }

cb_fail:
    free(out);
    pcre2_match_data_free(match_data);
    pcre2_code_free(re);
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

    return NULL;
}

static int pm_ht_push(__hashtable__ *ht, size_t *idx, const char *data, size_t len)
{
    __string__ *part = pm_slice_to_string(data, len);
    if (NULL == part) {
        return -1;
    }
    __hashtable__setStringAt(ht, (*idx)++, part);

    return 0;
}

__hashtable__ *__compiler_preg_split(__string__ *pattern, __string__ *subject)
{
    const char *pat_full = pm_strdata(pattern);
    size_t pat_len = pm_strlen(pattern);
    const char *subj = pm_strdata(subject);
    size_t subj_len = pm_strlen(subject);
    __hashtable__ *ht;
    size_t idx = 0;
    PCRE2_SIZE start = 0;

    phpc_preg_last_error = PHPC_PREG_NO_ERROR;

    if (pat_len > PM_MAX_PATTERN) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    ht = __hashtable__alloc();
    if (NULL == ht) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    if (0 == subj_len) {
        if (0 != pm_ht_push(ht, &idx, "", 0)) {
            phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

            return NULL;
        }

        return ht;
    }

    char body[PM_MAX_PATTERN + 1];
    uint32_t opts = 0;
    if (0 != pm_extract_body(pat_full, pat_len, body, sizeof(body), &opts)) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code *re = pcre2_compile(
        (PCRE2_SPTR) body,
        PCRE2_ZERO_TERMINATED,
        opts,
        &errorcode,
        &erroffset,
        NULL
    );
    if (NULL == re) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    if (NULL == match_data) {
        pcre2_code_free(re);
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    while (start <= subj_len) {
        int rc = pcre2_match(
            re,
            (PCRE2_SPTR) subj,
            subj_len,
            start,
            0,
            match_data,
            NULL
        );

        if (PCRE2_ERROR_NOMATCH == rc) {
            if (0 != pm_ht_push(ht, &idx, subj + start, subj_len - start)) {
                goto split_fail;
            }
            break;
        }
        if (rc < 0) {
            goto split_fail;
        }

        PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
        if (0 != pm_ht_push(ht, &idx, subj + start, (size_t) (ovector[0] - start))) {
            goto split_fail;
        }
        if (ovector[1] == start) {
            start++;
        } else {
            start = ovector[1];
        }
        if (start >= subj_len) {
            if (0 != pm_ht_push(ht, &idx, "", 0)) {
                goto split_fail;
            }
            break;
        }
    }

    pcre2_match_data_free(match_data);
    pcre2_code_free(re);

    return ht;

split_fail:
    pcre2_match_data_free(match_data);
    pcre2_code_free(re);
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

    return NULL;
}
