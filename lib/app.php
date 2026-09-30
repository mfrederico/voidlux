<?php
/**
 * This app's wiring into the tiknix runtime — loaded on every request by Composer ("files" in
 * composer.json), before any controller runs. Fill the runtime's extension points here rather
 * than overriding its files:
 *
 *   \app\Chrome::add('prepare', 'app/nav');            // add to the sidebar menu (a view that edits $__sections)
 *   \app\Chrome::add('actions', 'app/topbar');         // a call to action in the top bar
 *   \app\Chrome::add('footer',  'app/footer');         // links in the footer
 *   \app\Chrome::$levelNames = [1 => 'Owner', 50 => 'Staff', 100 => 'Client', 101 => 'Guest'];
 *   \app\Contact::$categories['quote'] = ['label' => 'Quote requests', 'icon' => 'receipt',
 *       'statuses' => ['quoted' => 'success'], 'summary' => 'quote/_summary', 'details' => 'quote/_details'];
 *
 * Your home page is views/index/index.php — add it and / shows it in the layout (the runtime's
 * coming-soon page until then). A home page that is more than that (standalone, its own form):
 *
 *   \app\Index::$home = [\app\Home::class, 'index'];   // your controller renders it
 */
