---
description: "Validation rules for the custom request-header table"
---

# Custom Request Headers

Moved verbatim from `AGENTS.md`.

## The Custom Request-Header Table

Every rule the save enforces — a name in the RFC 7230 token set, reserved names
matched case-insensitively, printable-ASCII values, no empty value — is re-applied
where a header is READ, since a stored value can arrive from a hand-edited row or
an import that no form validated. A refusal names the rule, never who sets the
header: the reason must be true of every reserved name, not of the one example that
prompted the question.
**A value pattern is anchored `\z`, never `$`** — `$` also matches immediately
before a trailing newline, which is precisely the byte a printable-ASCII rule exists
to refuse, and a header value ending in one is a response-splitting sink.
**The header table gets no data patch or migration, deliberately.** The
single-value setting it replaces never reached a production release on any
platform, so no merchant ever had one configured; do not add one on the assumption
that stored values exist.
