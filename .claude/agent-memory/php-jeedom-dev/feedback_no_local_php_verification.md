---
name: feedback-no-local-php-verification
description: No `php` binary exists in this dev environment (Windows/Git Bash) — use a Python-based structural sanity check on the diff instead of `php -l`; NEVER put backslash-escape text (`\t`, `\x00`, `\d`) in a Bash command string (any form: `-c`, heredoc, quoted heredoc — all collapse it) — use the Write/Edit tool instead, and verify with a byte-level scan afterward.
metadata:
  type: feedback
---

`php -v`/`php -l` are unavailable in this repo's dev shell (Git Bash on Windows) — confirmed via
`which php` returning nothing. `CLAUDE.md` already flags that validation runs in CI, but CI only runs on
push/PR, so there's no fast local signal during an editing loop.

**Why:** for a large PHP class file (`core/class/<id>.class.php` can grow to thousands of lines),
eyeballing brace/paren balance after an Edit is error-prone, and a `*/`-inside-a-docblock or an unbalanced
bracket breaks prod undetected until CI.

**How to apply:** `python` (not `python3`/`py` — those may resolve to broken Windows Store shims in this
environment; `python` resolves to the real interpreter) is available and can run a small script that strips
PHP `//`/`#`/`/* */` comments and `'...'`/`"..."` string literals (with backslash-escape handling), then
counts `{}`/`()`/`[]` balance and tracks a running depth to catch a close-before-matching-open. This catches
the exact class of bug (`*/` closing early, unbalanced brackets) that `php -l` would catch, without a PHP
install. **Write the script to a file with the `Write` tool first, then run it with Bash** — passing it
inline via a Bash heredoc mangles backslash escapes (`'\\'` inside the command string gets collapsed to
`'\'`, causing a Python `SyntaxError`) because of how the Bash tool's command parameter handles escaping.
This is a supplement to reading the diff carefully, not a replacement — it doesn't catch semantic bugs,
only structural/lexical ones.

**Generalizes beyond Python**: the same backslash-collapsing hits **any** inline script text passed as a
Bash tool command argument — e.g. an inline `perl -e '...s/\\t/\t/g...'` meant to convert literal two-char
`\t`/`\n` escape sequences into real tab/CRLF bytes; the double backslashes silently become single
backslashes before reaching perl, so the substitution runs on the wrong pattern and produces garbled output
that has to be reverted (`git checkout --`) and redone. **Fix that actually works**: never put
escape-sequence *text* (`\t`, `\n`, `\\`) into a Bash command string at all — write the **real**
tab/newline characters directly into a scratch file via the `Write` tool (the tool call's own string
decoding handles those correctly, unlike a nested Bash-command string), then do purely *byte-level*
file-to-file operations (`sed 's/^/\t\t\t\t/'` to flat-prepend real tab characters already typed literally
in the sed script, or a `perl -0777` script that reads two files and splices their raw bytes with
`s/\r\n/\n/;s/\n/\r\n/` for line-ending normalization). See [[feedback-edit-tool-tab-indented-files]] for
the full recipe.

**Refinement — the balance checker must respect `<?php ?>` tag boundaries on mixed HTML/PHP files**
(`desktop/php/*.php`). Running the naive whole-file comment/string-stripper (designed for pure-PHP
`core/class/*.php`) on a template file produces a false-positive brace imbalance. Root cause: raw HTML text
sitting *outside* `<?php ?>` tags routinely contains a bare apostrophe (a French contraction, e.g. `l'API`,
inside an `<!-- HTML comment -->`) which the stripper — unaware it isn't looking at PHP — reads as the
*start of a single-quoted string literal*, then silently swallows everything up to the next `'` as "inside
a string", desyncing all brace counts that follow. **Fix**: before stripping, first split the file on
`<?php` / `?>` and concatenate only the PHP segments; run the comment/string stripper (and the balance
count) on that PHP-only text, ignoring HTML segments entirely (HTML's own `{{...}}` i18n markers and stray
punctuation are not PHP syntax). Sanity-check the tool itself by also running it against the file's
last-committed (`git show HEAD:<path>`) version — if HEAD already reports a nonzero imbalance, the checker
(not the new edit) is broken.

**Fresh recurrence (2026-08-24, same session, despite the paragraph above already existing)**: needed to
insert a PHP regex `preg_replace('/[\x00-\x1F\x7F]/', '', $value)` into a CRLF file via an inline
`python3 -c "..."` Bash command, with the backslashes escaped multiple times to try to survive both Bash's
and Python's parsing layers. It didn't survive: the shell/Python quoting interaction collapsed the escapes
and wrote the **actual raw control bytes** (real NUL 0x00, real 0x1F, real 0x7F) into the PHP source
instead of the literal 4-character text "\x00" etc. This was *semantically* almost invisible — PCRE happily
accepts literal raw bytes as character-class range bounds, so the regex still behaved identically at
runtime — which makes it a dangerous, easy-to-miss corruption: no syntax error, no balance-checker failure,
just a source file with embedded control bytes that a diff tool, prettier bot, or editor could mangle
later. **How it was caught**: reading the raw file bytes back with `repr()` on a byte slice — a real
NUL/0x1F/0x7F byte prints as `\x00`/`\x1f`/`\x7f` (Python's own repr escaping, lowercase hex) which looks
deceptively like the intended literal text at a glance; the actual tell was checking `b'\x00' in data`
(true only for the corrupted version) and the case mismatch (repr uses lowercase hex; the intended PHP
source used uppercase `\x1F`/`\x7F`). **How it was fixed**: located the exact corrupted line by its
distinctive surrounding bytes, then built the replacement in a **Python script file** (via the `Write`
tool, not an inline Bash `-c` string) using a **raw string literal** (`r"\x00-\x1F\x7F"`) — in a Python raw
string, a backslash is never an escape introducer, so the source text round-trips byte-for-byte into the
output. **Rule**: whenever the target text itself contains backslash-escape-*looking* sequences (regex
`\d`, `\x00`, `\s`, etc.) that must land as literal characters in the destination file, treat it exactly
like the tab/newline case already documented above in this same memory — write it via a script file using
a Python raw string (or reach for the `Edit`/`Write` tool directly on the target file, which handled this
exact case correctly once tried), never as escaped text inside a Bash `-c` argument or heredoc processed by
a non-raw string.

**Second recurrence of the exact same bug, in a heredoc this time (2026-08-25)**: the rule above was
written down after the first recurrence, then broken again a few turns later in the same feature — this
time via `python3 - <<'PYEOF' ... PYEOF` (a *quoted* heredoc delimiter, which POSIX guarantees receives
zero shell expansion) containing a normal, non-raw Python triple-quoted string with a line meant to read
`(hors \t\n\r\0\x0B)` in the output. Expectation: quoting the heredoc delimiter should make it immune,
since bash heredocs don't expand backslashes when the delimiter is quoted. Reality: the corruption
happened anyway — real TAB/CR/NUL/0x0B bytes ended up embedded in a docblock. The root cause is upstream
of bash's heredoc semantics entirely: it's the Bash **tool's own command-string decoding**, before the
text is ever handed to a shell, that collapses backslash sequences — and that layer doesn't care whether
the backslashes are destined for a heredoc, a `-c` argument, or anything else. **Corollary**: no
Bash-invocation shape (heredoc, quoted heredoc, `-c`, single vs double quotes) is safe for shipping
literal backslash-escape text through the Bash tool's command parameter — full stop. The only reliable
pattern is: write the target text to a file with the **`Write` tool** (or edit it with the **`Edit`**
tool directly — its `old_string`/`new_string` parameters are not Bash-command text either, and correctly
preserved a literal `\x00-\x1F\x7F` sequence when used directly on the target file in this same session),
using Python raw strings (`r"..."`) if going through a script file. **Mandatory post-check** after any
edit that introduces `\x`, `\d`, `\s`, `\t`-style text into a target file: scan the written bytes for the
actual control byte each escape names (NUL, tab 0x09, 0x0B, 0x1F, 0x7F, etc. — e.g.
`b'\x00' in open(path,'rb').read()` from a script *file*, not an inline command) — do this **before**
declaring the edit done, not after a reviewer catches it.
