---
name: feedback-coordinator-correction-round-scope
description: During a /feature post-review correction round, implement exactly the coordinator's numbered finding list — not extra detail already present in the tech spec the coordinator is amending in parallel; and prefer `tr -cd '\r'/'\n' | wc -c` byte counts over `grep -c $'\r'` (shell-dependent) when a coordinator's claim about a file's line-endings needs settling.
metadata:
  type: feedback
---

Observed on UC01 (`smartclim`) when the `/feature` coordinator forwarded cross-review verdicts
(`code-reviewer` + `security-reviewer`) as a numbered list of findings to fix, after the initial
implementation had already been delivered.

**Rule 1 — the finding list is the contract, not the tech spec being edited live.** The coordinator
explicitly said: "je mets les specs à jour moi-même en parallèle … ne code donc pas « d'après la spec
technique actuelle » sur les points que je liste comme amendés." Reading `<nom>-tech.md` mid-correction
showed it already contained a *stricter* rule than what was asked (e.g. it added control-character
stripping on an email `preConfig_*` hook, a detail absent from the actual numbered finding). Implementing
that extra rule would mean coding from a racing/interim document instead of what the user actually
validated in the finding list.

**Why:** the tech spec file is being rewritten concurrently by the orchestrator to describe the *final*
state; at any instant it may already reflect a next iteration the user hasn't seen applied, or a
refinement the coordinator hasn't yet decided to forward. Treat it as read-only context for confirming
signatures/contracts already given (e.g. `config::remove($key, $plugin)`), never as an extra source of
requirements during a correction round.

**How to apply:** implement strictly the coordinator's numbered findings. If the tech spec (or any other
`.memory/` file) suggests something adjacent-but-broader while you're in there for confirmation, do not
silently adopt it — note the discrepancy plainly in the final report and let the coordinator decide.

**Rule 2 — trust your own measurement over a coordinator's factual claim, but still obey an explicit
"leave it alone".** The same message asserted a specific file was CRLF "confirmed, 111/111 lines carry a
CR" and instructed not to re-save it. Direct measurement (`file`, `grep -c $'\r'`, a byte-count script)
showed the opposite: pure LF, 0 CR bytes. The correct response is neither to silently "fix" it nor to
silently agree — do the requested no-op (don't touch that file's line endings) since the instruction to
leave it alone stands regardless of who's factually right, but say plainly in the report what was actually
measured, so the coordinator/user can reconcile it.

**Resolution (same feature, next correction round)**: the coordinator re-checked with byte-level counting
(`tr -cd '\r' | wc -c` vs `tr -cd '\n' | wc -c`) and confirmed the original measurement was right — their
first check had been invalid (`grep -c $'\r'` had returned the *total line count* for every one of six
files tested, i.e. it was spuriously matching every line rather than actually testing for a CR byte, in
*their* shell). `grep -c $'\r'` is therefore not reliable across environments even though it happened to
give the right answer when this agent ran it in this repo's shell earlier in the same session — prefer the
`tr -cd '\r' | wc -c` / `tr -cd '\n' | wc -c` byte-count comparison (CR count should equal LF count on a
consistently-terminated file) as the portable, unambiguous check going forward. Converting LF → CRLF to
match a repo's convention is a plain byte substitution (`data.replace(b'\n', b'\r\n')` after asserting no
`\r` already present) — verify after with the same `tr -cd` byte counts, not `grep`.
See also [[feedback-no-local-php-verification]] for the byte-level verification techniques used here.
