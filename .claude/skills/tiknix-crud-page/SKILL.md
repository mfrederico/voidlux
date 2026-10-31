---
name: tiknix-crud-page
description: "Use when adding a feature that lists, creates, edits and deletes records in a tiknix app (customers, bookings, products…): the controller, views, permission seed and menu link, in the order that avoids the usual mistakes."
---

# A list / create / edit / delete feature in a tiknix app

Do these in order. Each step names the mistake it prevents.

1. **Look before you build.** Call `reuse_digest`, then `describe("<name>")` on anything close.
   If a model, controller or lib already covers part of this, extend it — a near-duplicate is a
   defect. Read the project notebook's Map and Decisions if your brief did not include them
   (`agent/notebook/`).
2. **Ask which pages it needs.** `ui_pattern(bean: "<bean>")` — or with `columns: [...]` before
   the table exists. Not everything is a table: a thing that happens at a time is an agenda,
   replies are a conversation, one-per-member is a single form. Build the pattern it names; the
   steps below are for the usual one (a list and a form).
2b. **Scaffold, then edit.** `php scripts/clitool.php --scaffold=all --bean=<bean>` writes the
   model, controller, views and API in this app's conventions. Start from that, not from a blank
   file. The bean name is one lowercase word (`booking`, not `class_booking`).
3. **The controller is the app's own**: `controls/<Name>.php` → `/<name>`, `/<name>/create`,
   `/<name>/edit?id=`, `/<name>/delete`. Never add a method to a runtime controller (`Admin`,
   `Member`, …) — it is not loaded from the app.
4. **Records are bean operations**: `Bean::dispense / load / store / trash / find`. No
   `Bean::exec('INSERT…')` for a single row, no raw `R::`. Relations through `ownXList`, not
   hand-set `_id` columns. A string id from another system ends in `_eid`.
5. **Every POST checks CSRF first** (`$this->validateCSRF()`).
   **The pages are described with `\app\Ui`, not written as markup** (AGENTS.md → Interface):
   the list is `Ui::page()` + `Ui::table()` (with its `empty`), create/edit is `Ui::page()` with
   a `back` + `Ui::form()`, a record's page is `Ui::detail()`. It escapes, adds the CSRF field,
   confirms a delete and lays the page out — so do not add cards, tables, alerts, flash output
   or `confirm()` of your own. One primary action per page; a refusal from `Ui` names its fix.
6. **Who may do what is a seed, written BEFORE you load the page once**:
   `services/Schema/Seeds/NN_<Name>.php` (NN from 50 up) with
   `echo \app\PermissionCache::seedRule('<name>', '*', 100, '…');` — 100 members, 50 admins,
   101 public. Loading an unseeded route first creates an admin-only row that then wins. Apply it
   in your sandbox with `php scripts/clitool.php --build`. Starter data goes in the same seed,
   idempotent. Never `database/seeds/` — nothing runs it.
7. **Own data is checked in the controller, not only by level.** A member editing
   `/bookings/edit?id=7` must own booking 7: load it, compare its `member_id` to the session's,
   and refuse otherwise. Level 100 means "a member", not "this member".
8. **Link it.** A page nobody can click to is not done: add the list page to the app's menu
   (`views/app/nav.php`, registered in `lib/app.php` with `\app\Chrome::add('prepare', 'app/nav')`),
   or link it from the page it belongs under. The list links to create / edit / delete; edit and
   create link back to the list.
9. **Prove it.** `full_validation` on the controller (it reports an unlinked page) and on each
   view you wrote (it reports what the page does by hand that `Ui` decides). Then, in your
   sandbox: open the list as a member, create one, edit it, delete it; open it as a guest and as
   another member and see the refusal. Report what you saw, not what should happen.
10. **Leave it for the next agent**: `## Notebook` for anything non-obvious you decided or learned,
    then `## Handoff` — the bean, the routes, where the menu link is.
