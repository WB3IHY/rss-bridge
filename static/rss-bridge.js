function rssbridge_list_search() {
    var search = document.getElementById('searchfield').value;

    var bridgeCards = document.querySelectorAll('section.bridge-card');
    for (var i = 0; i < bridgeCards.length; i++) {
        var bridgeName = bridgeCards[i].getAttribute('data-ref');
        var bridgeShortName = bridgeCards[i].getAttribute('data-short-name');
        var bridgeDescription = bridgeCards[i].querySelector('.description');
        var bridgeUrlElement = bridgeCards[i].getElementsByTagName('a')[0];
        var bridgeUrl = bridgeUrlElement.toString();

        bridgeCards[i].style.display = 'none';
        if (!bridgeName || !bridgeUrl) {
            continue;
        }
        var searchRegex = new RegExp(search, 'i');
        if (bridgeName.match(searchRegex)) {
            bridgeCards[i].style.display = 'block';
        }
        if (bridgeShortName.match(searchRegex)) {
            bridgeCards[i].style.display = 'block';
        }
        if (bridgeDescription.textContent.match(searchRegex)) {
            bridgeCards[i].style.display = 'block';
        }
        if (bridgeUrl.match(searchRegex)) {
            bridgeCards[i].style.display = 'block';
        }
    }
}

function rssbridge_toggle_bridge(){
    var fragment = window.location.hash.substr(1);
    var bridge = document.getElementById(fragment);

    if(bridge !== null) {
        bridge.getElementsByClassName('showmore-box')[0].checked = true;
    }
}

function rssbridge_use_placeholder_value(sender) {
    let inputId = sender.getAttribute('data-for');
    let inputElement = document.getElementById(inputId);
    inputElement.value = inputElement.getAttribute("placeholder");
}

var rssbridge_feed_finder = (function() {
    /*
     * Code for "Find feed by URL" feature
     */

    // Start the Feed search
    async function rssbridge_feed_search(event) {
        const input = document.getElementById('searchfield');
        let content = encodeURIComponent(input.value);
        if (content) {
            const findfeedresults = document.getElementById('findfeedresults');
            findfeedresults.innerHTML = 'Searching for matching feeds ...';
            let baseurl = window.location.protocol + window.location.pathname;
            let url = baseurl + '?action=findfeed&format=Html&url=' + content;
            const response = await fetch(url);
            if (response.ok) {
                const data = await response.json();
                rss_bridge_feed_display_found_feed(data);
            } else {
                rss_bridge_feed_display_feed_search_fail();
            }
        } else {
            rss_bridge_feed_display_find_feed_empty();
        }
    }

    // Display the found feeds
    function rss_bridge_feed_display_found_feed(obj) {
        const findfeedresults = document.getElementById('findfeedresults');

        let content = 'Found Feed(s) :';

        // Let's go throug every Feed found
        for (const element of obj) {
            content += `<div class="search-result">
                        <div class="icon">
                            <img src="${element.bridgeMeta.icon}" width="60" />
                        </div>
                        <div class="content">
                        <h2><a href="${element.url}">${element.bridgeMeta.name}</a></h2>
                        <p>
                        <span class="description"><a href="${element.url}">${element.bridgeMeta.description}</a></span>
                        </p>
                        <div>
                            <ul>`;

            // Now display every Feed parameter
            for (const param in element.bridgeData) {
                content += `<li>${element.bridgeData[param].name} : ${element.bridgeData[param].value}</li>`;
            }
            content += `</div>
              </div>
            </div>`;
        }
        content += '<p><div class="alert alert-info" role="alert">This feed may be only one of the possible feeds. You may find more feeds using one of the bridges with different parameters, for example.</div></p>';
        findfeedresults.innerHTML = content;
    }

    // Display an error if no feed were found
    function rss_bridge_feed_display_feed_search_fail() {
        const findfeedresults = document.getElementById('findfeedresults');
        findfeedresults.innerHTML = 'No Feed found !<div class="alert alert-info" role="alert">Not every bridge supports feed detection. You can check below within the bridge parameters to create a feed.</div>';
    }

    // Empty the Found Feed section
    function rss_bridge_feed_display_find_feed_empty() {
        const findfeedresults = document.getElementById('findfeedresults');
        findfeedresults.innerHTML = '';
    }

    // Add Event to 'Detect Feed" button
    var rssbridge_feed_finder = function() {
        const button = document.getElementById('findfeed');
        button.addEventListener("click", rssbridge_feed_search);
        button.addEventListener("keyup", rssbridge_feed_search);
    };
    return rssbridge_feed_finder;
}());

// Escapes untrusted text before it's inserted into innerHTML. Everything rendered by
// the discover feature (feed titles, scraped sample text, issue messages) comes from
// whatever third-party site the user asked to check, not from rss-bridge itself.
function rssbridge_escape_html(value) {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
}

// Only http(s) URLs are rendered as clickable links. A malicious site's <link rel="alternate">
// could set href="javascript:..."; refusing anything but http(s) here closes that off.
function rssbridge_safe_link(url, label) {
    const safeLabel = rssbridge_escape_html(label);
    if (typeof url === 'string' && /^https?:\/\//i.test(url)) {
        return '<a href="' + rssbridge_escape_html(url) + '">' + safeLabel + '</a>';
    }
    return safeLabel;
}

var rssbridge_discover_finder = (function() {
    /*
     * Code for "Discover a feed for any site" feature
     */

    async function rssbridge_discover_search() {
        const input = document.getElementById('discoverfield');
        const url = input.value.trim();
        const resultsEl = document.getElementById('discoverresults');
        if (!url) {
            resultsEl.innerHTML = '';
            return;
        }
        resultsEl.innerHTML = 'Checking &hellip;';
        const baseurl = window.location.protocol + '//' + window.location.host + window.location.pathname;
        const requestUrl = baseurl + '?action=discover&url=' + encodeURIComponent(url);
        let response;
        let data;
        try {
            response = await fetch(requestUrl);
            data = await response.json();
        } catch (e) {
            resultsEl.innerHTML = '<div class="alert alert-error" role="alert">Discovery request failed.</div>';
            return;
        }
        if (!response.ok) {
            resultsEl.innerHTML = '<div class="alert alert-error" role="alert">'
                + rssbridge_escape_html(data.message || 'Discovery failed')
                + '</div>';
            return;
        }
        rssbridge_discover_render(data);
    }

    function rssbridge_discover_render(data) {
        const resultsEl = document.getElementById('discoverresults');
        let html = '';

        html += '<h4>Native feeds</h4>';
        if (data.nativeFeeds && data.nativeFeeds.length > 0) {
            for (const feed of data.nativeFeeds) {
                html += '<div class="search-result">';
                html += '<p>' + rssbridge_safe_link(feed.url, feed.title || feed.url)
                    + ' (' + parseInt(feed.itemCount, 10) + ' items)</p>';
                if (feed.sampleTitles && feed.sampleTitles.length > 0) {
                    html += '<ul>';
                    for (const title of feed.sampleTitles) {
                        html += '<li>' + rssbridge_escape_html(title) + '</li>';
                    }
                    html += '</ul>';
                }
                if (feed.issues && feed.issues.length > 0) {
                    html += '<div class="alert alert-warning" role="alert"><strong>Possible issues:</strong><ul>';
                    for (const issue of feed.issues) {
                        html += '<li>' + rssbridge_escape_html(issue) + '</li>';
                    }
                    html += '</ul></div>';
                }
                html += '</div>';
            }
        } else {
            html += '<p>No native feed found on this page.</p>';
        }

        html += '<h4>Suggested scrape-based configuration</h4>';
        if (data.suggestedScrapeConfig) {
            const cfg = data.suggestedScrapeConfig;
            html += '<div class="search-result">';
            // suggestedUrl is relative (e.g. "?action=display&..."), always generated by
            // rss-bridge's own server-side code, never attacker-influenced - resolve it
            // to absolute so it passes the same http(s)-only check as everything else here.
            const baseurl = window.location.protocol + '//' + window.location.host + window.location.pathname;
            html += '<p>' + rssbridge_safe_link(baseurl + cfg.suggestedUrl, 'Try suggested feed') + '</p>';
            html += '<ul>';
            html += '<li>Entry selector: <code>' + rssbridge_escape_html(cfg.entrySelector) + '</code></li>';
            if (cfg.urlSelector) {
                html += '<li>URL selector: <code>' + rssbridge_escape_html(cfg.urlSelector) + '</code></li>';
            }
            html += '<li>Matched ' + parseInt(cfg.matchCount, 10) + ' entries, '
                + parseInt(cfg.distinctUrlCount, 10) + ' distinct URLs</li>';
            html += '</ul>';
            if (cfg.sampleTexts && cfg.sampleTexts.length > 0) {
                html += '<p>Sample matched text:</p><ul>';
                for (const sample of cfg.sampleTexts) {
                    html += '<li>' + rssbridge_escape_html(sample) + '</li>';
                }
                html += '</ul>';
            }
            if (cfg.urlSelectorWarning) {
                html += '<div class="alert alert-warning" role="alert">'
                    + rssbridge_escape_html(cfg.urlSelectorWarning) + '</div>';
            }
            html += '</div>';
        } else {
            html += '<p>No confident scrape-based suggestion could be produced for this page.</p>';
        }

        if (data.note) {
            html += '<div class="alert alert-info" role="alert">' + rssbridge_escape_html(data.note) + '</div>';
        }

        resultsEl.innerHTML = html;
    }

    var rssbridge_discover_finder = function() {
        const button = document.getElementById('discoverfeed');
        button.addEventListener('click', rssbridge_discover_search);
        const input = document.getElementById('discoverfield');
        input.addEventListener('keyup', function(event) {
            if (event.key === 'Enter') {
                rssbridge_discover_search();
            }
        });
    };
    return rssbridge_discover_finder;
}());
