<?php
/**
 * The app's tests: Composer's autoloader (which also loads lib/app.php, the app's wiring into
 * the runtime) and nothing else — no database, no request. A test that needs a database opens
 * its own in-memory one:
 *
 *   \app\Bean::addDatabase('test', 'sqlite::memory:'); \app\Bean::selectDatabase('test');
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';
