
<script>
    document.addEventListener('DOMContentLoaded', rssbridge_toggle_bridge);
    document.addEventListener('DOMContentLoaded', rssbridge_list_search);
    document.addEventListener('DOMContentLoaded', rssbridge_feed_finder);
    document.addEventListener('DOMContentLoaded', rssbridge_discover_finder);
</script>

<section class="searchbar">
    <h3>Search</h3>
    <input
        type="text"
        name="searchfield"
        id="searchfield"
        placeholder="Insert URL or bridge name"
        onchange="rssbridge_list_search()"
        onkeyup="rssbridge_list_search()"
        value=""
    >
    <button
        type="button"
	    id="findfeed"
        name="findfeed"
    >Find Feed from URL</button>
    <section id="findfeedresults">
    </section>

</section>

<section class="searchbar">
    <h3>Discover a feed for any site</h3>
    <p>
        Checks for a native RSS/Atom feed on the given page, and separately proposes a
        best-effort scraped selector configuration as a fallback (or an alternative, if the
        native feed exists but isn't good enough). Always verify the result before subscribing.
    </p>
    <input
        type="text"
        name="discoverfield"
        id="discoverfield"
        placeholder="https://example.com/blog/"
        value=""
    >
    <button
        type="button"
        id="discoverfeed"
        name="discoverfeed"
    >Discover Feed</button>
    <section id="discoverresults">
    </section>

</section>

<?= raw($bridges) ?>

<section class="footer">
    <a href="https://github.com/RSS-Bridge/rss-bridge">
        https://github.com/RSS-Bridge/rss-bridge
    </a>

    <br>
    <br>

    <p class="version">
        <?= e(Configuration::getVersion()) ?>
    </p>

    <?= $active_bridges ?>/<?= $total_bridges ?> active bridges.<br>

    <br>

    <?php if ($admin_email): ?>
        <div>
            Email: <a href="mailto:<?= e($admin_email) ?>"><?= e($admin_email) ?></a>
        </div>
    <?php endif; ?>

    <?php if ($admin_telegram): ?>
        <div>
            Url: <a href="<?= e($admin_telegram) ?>"><?= e($admin_telegram) ?></a>
        </div>
    <?php endif; ?>

</section>
