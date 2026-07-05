<?php declare(strict_types=1);

class SharkRFM1KEBridge extends BridgeAbstract
{
    const NAME        = 'SharkRF M1KE Changelog';
    const URI         = 'https://www.sharkrf.com/products/m1ke/changelog/beta/';
    const DESCRIPTION = 'Returns firmware changelog entries (stable and beta) for the SharkRF M1KE device.';
    const MAINTAINER  = 'WB3IHY';
    const PARAMETERS  = [
        '' => [
            'filter' => [
                'name'     => 'Release type',
                'type'     => 'list',
                'values'   => [
                    'All releases'   => 'all',
                    'Stable only'    => 'stable',
                    'Beta only'      => 'beta',
                ],
                'defaultValue' => 'all',
            ],
        ],
    ];
    const CACHE_TIMEOUT = 3600; // 1 hour

    // Path to curl-impersonate binary
    const CURL_IMPERSONATE = '/usr/local/bin/curl-impersonate';

    /**
     * Fetch a URL using curl-impersonate, which produces a genuine Firefox
     * TLS fingerprint (JA3/JA4) that passes Cloudflare's bot detection.
     */
    private function fetchWithCurlImpersonate(string $url): string
    {
        $binary = self::CURL_IMPERSONATE;

        if (!is_executable($binary)) {
            returnServerError('curl-impersonate not found at ' . $binary . '. Please install it to /usr/local/bin/.');
        }

        // Build the command using the exact TLS flags confirmed to return HTTP 200
        $cmd = implode(' ', [
            escapeshellcmd($binary),
            '--tls13-ciphers', escapeshellarg('TLS_AES_128_GCM_SHA256:TLS_CHACHA20_POLY1305_SHA256:TLS_AES_256_GCM_SHA384'),
            '--ciphers',       escapeshellarg('ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384'),
            '--curves',        escapeshellarg('X25519:P-256:P-384:P-521'),
            '--tls-permute-extensions',
            '--http2',
            '-L',
            '--max-redirs', '5',
            '--max-time', '30',
            '--compressed',
            '-A', escapeshellarg('Mozilla/5.0 (X11; Linux x86_64; rv:135.0) Gecko/20100101 Firefox/135.0'),
            '-H', escapeshellarg('Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'),
            '-H', escapeshellarg('Accept-Language: en-US,en;q=0.9'),
            '-H', escapeshellarg('Upgrade-Insecure-Requests: 1'),
            '-H', escapeshellarg('Sec-Fetch-Dest: document'),
            '-H', escapeshellarg('Sec-Fetch-Mode: navigate'),
            '-H', escapeshellarg('Sec-Fetch-Site: none'),
            '-H', escapeshellarg('Sec-Fetch-User: ?1'),
            '--silent',
            '--output', '-',   // output body to stdout
            escapeshellarg($url),
        ]);

        $html = shell_exec($cmd);

        if ($html === null || $html === '') {
            returnServerError('curl-impersonate returned an empty response. Cloudflare may have updated its challenge.');
        }

        // Detect a Cloudflare error page in the response body
        if (str_contains($html, 'cf-mitigated') || str_contains($html, 'Attention Required')) {
            returnServerError('Cloudflare is still blocking the request. The TLS fingerprint may need updating.');
        }

        return $html;
    }

    public function collectData(): void
    {
        $filter = $this->getInput('filter') ?? 'all';

        $html = $this->fetchWithCurlImpersonate(self::URI);
        $dom  = str_get_html($html);

        if ($dom === false) {
            returnServerError('Could not parse SharkRF changelog page.');
        }

        // Each release is an <h2> tag, e.g. <h2>v57 (beta)</h2>
        // followed by a <ul> with bullet points.
        foreach ($dom->find('h2') as $h2) {
            $rawTitle = trim($h2->plaintext);

            // Skip the page-level headings that are not version entries.
            // Version headings match the pattern: v<number> (<type>)
            if (!preg_match('/^v(\d+)\s*\((stable|beta)\)$/i', $rawTitle, $matches)) {
                continue;
            }

            $version     = (int) $matches[1];
            $releaseType = strtolower($matches[2]); // 'stable' or 'beta'

            // Apply filter
            if ($filter !== 'all' && $releaseType !== $filter) {
                continue;
            }

            // Collect the <ul> that immediately follows the <h2>
            $contentHtml = '';
            $next = $h2->next_sibling();
            while ($next !== null && $next->tag !== 'h2') {
                if ($next->tag === 'ul') {
                    $contentHtml .= $next->outertext;
                }
                $next = $next->next_sibling();
            }

            $title = 'M1KE ' . $rawTitle;

            // Use the version number as a stable uid so the entry doesn't
            // re-appear as "new" every time the page is fetched.
            $uid = 'sharkrf-m1ke-v' . $version;

            $this->items[] = [
                'uri'     => self::URI . '#' . urlencode(strtolower(str_replace(' ', '-', $rawTitle))),
                'title'   => $title,
                'content' => $contentHtml ?: '<p>(No details listed)</p>',
                'uid'     => $uid,
                'categories' => [$releaseType],
            ];
        }

        // Present newest versions first (highest version number first).
        // The page already lists them in descending order, but let's be explicit.
        usort($this->items, function ($a, $b) {
            // Extract version numbers from uids: sharkrf-m1ke-v<N>
            $va = (int) preg_replace('/\D/', '', $a['uid']);
            $vb = (int) preg_replace('/\D/', '', $b['uid']);
            return $vb - $va;
        });
    }
}
