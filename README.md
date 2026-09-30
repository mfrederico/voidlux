# A tiknix app

Your code lives at the root of this repository. The **tiknix runtime** (routing, members
and auth, the admin, connections, pipelines, the MCP server, plugins) is a Composer package
installed at `vendor/tiknix/runtime/`, pinned in `composer.lock`.

## Start

```bash
git remote rename origin template                # the template is where you came from, not where builds arrive
composer install
cp conf/config.example.ini conf/config.ini      # then set [app] name and baseurl, [security] app_key
php scripts/clitool.php --build                  # create the database and seed it
php scripts/clitool.php --adduser=you@example.com --password='…' --level=1
php scripts/clitool.php --agent-sync             # write CLAUDE.md for your coding agent
```

Point the web server's document root at `public/`. Everything the runtime serves (its pages,
`/rt/` assets, `/mcp/message`) works with no further setup.

## Build on it

| Put this | Here |
|---|---|
| controllers (`/thing/method` routes itself) | `controls/Thing.php` |
| views | `views/thing/method.php` |
| RedBean models | `models/Model_Thing.php` |
| services and helpers | `lib/`, `services/` |
| seed data and permission rows | `services/Schema/Seeds/NN_Name.php` |
| your own MCP tools | `mcptools/` |
| extra agent guidance | `agent/guidelines/NNN-name.md` |
| your home page | `views/index/index.php` |
| tests (`vendor/bin/phpunit`) | `tests/unit/` |
| wiring into the runtime (menu items, footer links, level names, support categories) | `lib/app.php` |

**Never edit `vendor/tiknix/runtime/`** — the code-standards hook refuses it, and the next
update would overwrite it. Extend the runtime (slots, settings, your own classes) or override
one file: `php scripts/clitool.php --override=views/dashboard/index.php` copies it into your
app, where your copy wins. An overridden file is yours from then on; `--overrides` lists them.

## Update

`php scripts/clitool.php --update` moves the runtime to its newest release, runs the seeds,
and names any override the runtime changed underneath you (STALE).
