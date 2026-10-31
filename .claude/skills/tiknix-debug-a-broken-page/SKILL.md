---
name: tiknix-debug-a-broken-page
description: "Use when a page of a tiknix app answers 500, 404, 403 or a blank screen, or 'worked once and fails the next time': where to look first and what each symptom usually is."
---

# A page that does not answer

**Logs first, always.** `last_error` (or `read_log_entries`), else
`tail -50 log/app-$(date +%Y-%m-%d).log`. Do not read code to guess at what the log already says.

| What you see | What it usually is | Check |
|---|---|---|
| 404 on a route you just wrote | The class is not loaded: wrong file name or namespace, or you added a method to a RUNTIME controller (not loaded from the app) | `describe("<Controller>")` lists its routes; the file is `controls/<Name>.php`, class `<Name>`, `namespace app` |
| 404 with an EMPTY log | An exception thrown before logging — a deprecated call (`curl_close()` on PHP 8.5) or a parse error in a file loaded early | `php -l` the files you touched; `full_validation` |
| 403 / sent to sign in, for someone who should get in | The permission row: none seeded and the first request made an admin-only one, or a method row shadowing your wildcard | `database_schema("authcontrol")` then query the rows for the controller; fix it in the SEED with `seedRule`, then `php scripts/resetcache.php` |
| 500 "no such table / column" | The seed that declares it did not run here, or the property is misspelled (RedBean adds a column on first store, so a typo is a new empty column) | `database_schema("<table>")`; `php scripts/clitool.php --build` |
| Works once, fails on the next request | Something cached a first answer — schema, permissions | `php scripts/resetcache.php`, then reproduce twice |
| 500 "Undefined variable" in a view | The controller did not pass it. Do not default it in the view — a controller/view mismatch should surface | Pass it from the controller |
| A page shows another app's or no data | Reading the wrong database or site | Which database is selected; is the bean `scoped` to a site |

Fix the cause the log names. Do not add a `try/catch` that returns an empty value to make the
error go away — a broken thing that answers plausibly gets believed (No Fallbacks).

When it is fixed, load the page TWICE, as the role that reported it, and write what you saw.
If the cause was something the next agent would also hit, put it in `## Notebook` as a `lesson`.
