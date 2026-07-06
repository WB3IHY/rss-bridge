<?php

declare(strict_types=1);

/**
 * Looks for native RSS/Atom feeds on a given webpage: <link rel="alternate">
 * tags plus a handful of well-known feed paths. This is purely informational —
 * it never decides that scraping is unnecessary, it just reports what native
 * feeds (if any) already exist so the caller can compare against a scraped
 * alternative.
 */
class DiscoverAction implements ActionInterface
{
    private const COMMON_FEED_PATHS = [
        'feed',
        'feed/',
        'rss.xml',
        'rss',
        'atom.xml',
        'index.xml',
    ];

    public function __invoke(Request $request): Response
    {
        $url = $request->get('url');
        if (!$url) {
            return new Response(Json::encode(['message' => 'You must specify a url']), 400, ['content-type' => 'application/json']);
        }
        if (!Url::validate($url)) {
            return new Response(Json::encode(['message' => 'Invalid url']), 400, ['content-type' => 'application/json']);
        }

        $candidateUrls = [];

        try {
            $html = $this->fetchHtmlWithHttp1Fallback($url);
        } catch (\Throwable $e) {
            return new Response(Json::encode(['message' => 'Could not fetch url: ' . $e->getMessage()]), 502, ['content-type' => 'application/json']);
        }

        foreach ($html->find('link[rel=alternate]') as $link) {
            $type = strtolower((string) $link->type);
            if ($type === 'application/rss+xml' || $type === 'application/atom+xml') {
                $href = html_entity_decode((string) $link->href);
                if ($href) {
                    $candidateUrls[] = urljoin($url, $href);
                }
            }
        }

        foreach (self::COMMON_FEED_PATHS as $path) {
            $candidateUrls[] = urljoin($url, $path);
        }

        $candidateUrls = array_values(array_unique($candidateUrls));

        $feeds = [];
        $feedParser = new FeedParser();
        foreach ($candidateUrls as $candidateUrl) {
            try {
                $xml = $this->getContentsWithHttp1Fallback($candidateUrl);
                $parsed = $feedParser->parseFeed($xml);
            } catch (\Throwable $e) {
                continue;
            }

            $sampleTitles = array_slice(array_map(
                fn ($item) => $item['title'] ?? '',
                $parsed['items']
            ), 0, 3);

            $feeds[] = [
                'url' => $candidateUrl,
                'title' => $parsed['title'],
                'itemCount' => count($parsed['items']),
                'sampleTitles' => $sampleTitles,
                'issues' => $this->findFeedIssues($xml, $parsed['items']),
            ];
        }

        $suggestedScrapeConfig = $this->buildSuggestedScrapeConfig($html, $url);

        $result = [
            'url' => $url,
            'nativeFeeds' => $feeds,
            'suggestedScrapeConfig' => $suggestedScrapeConfig,
            'note' => 'This only reports native feeds already published by the site, and whether our lenient parser could read them. '
                . 'Our parser is deliberately permissive, so a clean parse here is NOT proof a feed is usable in a real reader like FreshRSS — '
                . 'see the "issues" list per feed for known FreshRSS-breaking symptoms we do check for. '
                . 'suggestedScrapeConfig is a best-effort heuristic guess at an article-list selector, offered regardless of what '
                . 'native feeds were found above (some feeds are technically valid but too limited or broken to actually use) — '
                . 'verify it, don\'t trust it blindly, and tune the selector by hand if it picked the wrong repeated element.',
        ];

        return new Response(Json::encode($result), 200, ['content-type' => 'application/json']);
    }

    /**
     * Some sites' CDNs negotiate an HTTP/2 connection that this server's curl
     * chokes on ("HTTP/2 stream ... PROTOCOL_ERROR") even though the site is
     * reachable fine over HTTP/1.1. Retry once, forced to HTTP/1.1, rather
     * than failing discovery outright on a transport-layer quirk.
     */
    private function isHttp2ProtocolError(\Throwable $e): bool
    {
        return stripos($e->getMessage(), 'HTTP/2') !== false
            && stripos($e->getMessage(), 'PROTOCOL_ERROR') !== false;
    }

    private function fetchHtmlWithHttp1Fallback(string $url): \simple_html_dom
    {
        try {
            return getSimpleHTMLDOM($url);
        } catch (\Throwable $e) {
            if (!$this->isHttp2ProtocolError($e)) {
                throw $e;
            }
            return getSimpleHTMLDOM($url, [], [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
        }
    }

    private function getContentsWithHttp1Fallback(string $url): string
    {
        try {
            return getContents($url);
        } catch (\Throwable $e) {
            if (!$this->isHttp2ProtocolError($e)) {
                throw $e;
            }
            return getContents($url, [], [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
        }
    }

    /**
     * Runs the entry-clustering heuristic against the already-fetched homepage
     * and, if it finds a plausible repeated article pattern, packages it as a
     * ready-to-use CssSelectorComplexBridge display URL plus the raw signals
     * behind the guess (match count, sample texts) so a human can judge it.
     *
     * @return array{entrySelector: string, matchCount: int, distinctUrlCount: int, sampleTexts: string[], suggestedUrl: string}|null
     */
    private function buildSuggestedScrapeConfig(\simple_html_dom $html, string $pageUrl): ?array
    {
        $candidate = (new EntryClusterDetector())->detect($html, $pageUrl);
        if ($candidate === null) {
            return null;
        }

        $query = http_build_query([
            'action' => 'display',
            'bridge' => 'CssSelectorComplexBridge',
            'format' => 'Atom',
            'home_page' => $pageUrl,
            'entry_element_selector' => $candidate['entry_selector'],
            'limit' => 15,
        ]);

        return [
            'entrySelector' => $candidate['entry_selector'],
            'matchCount' => $candidate['matchCount'],
            'distinctUrlCount' => $candidate['distinctUrlCount'],
            'sampleTexts' => $candidate['sampleTexts'],
            'suggestedUrl' => '?' . $query,
        ];
    }

    /**
     * Flags common symptoms that a feed will misbehave in a real reader (e.g.
     * FreshRSS) even though our own lenient FeedParser accepted it. This is a
     * shallow, best-effort check, not a spec validator.
     *
     * @param string $rawXml The raw feed body as fetched
     * @param array $items Items as parsed by FeedParser
     * @return string[] Human-readable issue descriptions, empty if none found
     */
    private function findFeedIssues(string $rawXml, array $items): array
    {
        $issues = [];

        if (!$items) {
            $issues[] = 'Feed parsed but contains no items';
            return $issues;
        }

        $missingUri = 0;
        $missingTimestamp = 0;
        $missingTitle = 0;
        $uris = [];
        foreach ($items as $item) {
            if (empty($item['uri'])) {
                $missingUri++;
            } else {
                $uris[] = $item['uri'];
            }
            if (empty($item['timestamp'])) {
                $missingTimestamp++;
            }
            if (empty($item['title'])) {
                $missingTitle++;
            }
        }

        $itemCount = count($items);
        if ($missingUri > 0) {
            $issues[] = sprintf(
                '%d of %d items have no link/guid — readers may not be able to dedupe or open them',
                $missingUri,
                $itemCount
            );
        }
        if (count($uris) !== count(array_unique($uris))) {
            $issues[] = 'Duplicate item links/guids found — readers may merge or drop items unexpectedly';
        }
        if ($missingTimestamp > (int) ceil($itemCount * 0.2)) {
            $issues[] = sprintf(
                '%d of %d items have no parseable date — sort order or "new item" detection may be wrong',
                $missingTimestamp,
                $itemCount
            );
        }
        if ($missingTitle > 0) {
            $issues[] = sprintf('%d of %d items have no title', $missingTitle, $itemCount);
        }

        // Declared encoding vs actual bytes: a classic source of mojibake that
        // a lenient XML parser will silently swallow but a reader will display broken.
        $declaredEncoding = 'UTF-8';
        if (preg_match('/<\?xml[^>]+encoding=["\']([^"\']+)["\']/i', $rawXml, $matches)) {
            $declaredEncoding = strtoupper($matches[1]);
        }
        if ($declaredEncoding === 'UTF-8' && !mb_check_encoding($rawXml, 'UTF-8')) {
            $issues[] = 'Feed declares UTF-8 but the body is not valid UTF-8 — likely to render as mojibake';
        }

        return $issues;
    }
}
