<?php declare(strict_types=1);

/**
 * Combines GoogleNewsSearchBridge and StartpageSearchBridge into a single
 * alert feed, with optional require/exclude regex filtering -- the
 * News+Web -> merge -> filter chain, done in one bridge instead of by
 * hand-chaining separate bridge URLs.
 */
class SearchAlertBridge extends BridgeAbstract
{
    const NAME = 'Search alert';
    const URI = 'https://github.com/WB3IHY/rss-bridge';
    const DESCRIPTION = 'Alerts on a name or phrase across Google News and Startpage web search, with optional require/exclude filtering.';
    const MAINTAINER = 'WB3IHY';
    const CACHE_TIMEOUT = 1800; // 30m

    const PARAMETERS = [[
        'q' => [
            'name' => 'keyword',
            'required' => true,
            'exampleValue' => '"Jane Doe"',
        ],
        'source_news' => [
            'name' => 'Include Google News results',
            'type' => 'checkbox',
            'defaultValue' => 'checked',
        ],
        'source_web' => [
            'name' => 'Include Startpage web results',
            'type' => 'checkbox',
            'defaultValue' => 'checked',
        ],
        'when' => [
            'name' => 'Only show results from the last...',
            'type' => 'list',
            'values' => [
                'Any time' => '',
                'Day' => 'd',
                'Week' => 'w',
                'Month' => 'm',
                'Year' => 'y',
            ],
            'defaultValue' => '',
        ],
        'require' => [
            'name' => 'Require (regex, optional)',
            'required' => false,
            'exampleValue' => 'obituary|died|funeral',
            'title' => 'Only keep items whose title/content/url matches this regex',
        ],
        'exclude' => [
            'name' => 'Exclude (regex, optional)',
            'required' => false,
            'exampleValue' => 'linkedin\.com|familysearch\.org',
            'title' => 'Drop items whose title/content/url matches this regex',
        ],
        'case_insensitive' => [
            'name' => 'Case-insensitive require/exclude',
            'type' => 'checkbox',
            'defaultValue' => 'checked',
        ],
    ]];

    // GoogleNewsSearchBridge's `when` values are day-based tokens (see its own
    // dropdown); reuse them here instead of re-deriving, since Google News
    // silently returns zero results for month/week units (see that bridge).
    const NEWS_WHEN_MAP = ['' => '', 'd' => '1d', 'w' => '7d', 'm' => '30d', 'y' => '1y'];

    public function collectData()
    {
        $q = $this->getInput('q');
        $when = $this->getInput('when');

        $newsOn = $this->getInput('source_news');
        $webOn = $this->getInput('source_web');
        if (!$newsOn && !$webOn) {
            // Neither explicitly selected (e.g. a hand-built URL omitting both
            // checkboxes) -- fall back to both rather than silently returning nothing.
            $newsOn = $webOn = true;
        }

        if ($newsOn) {
            $this->collectFromSource(GoogleNewsSearchBridge::class, [
                'q' => $q,
                'when' => self::NEWS_WHEN_MAP[$when] ?? '',
            ]);
        }

        if ($webOn) {
            $this->collectFromSource(StartpageSearchBridge::class, [
                'q' => $q,
                'when' => $when,
            ]);
        }

        $this->dedupeItems();
        $this->applyRequireExclude();

        usort($this->items, function ($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });
    }

    private function collectFromSource(string $bridgeClass, array $input): void
    {
        try {
            $source = new $bridgeClass($this->cache, $this->logger);
            $source->setInput($input);
            $source->collectData();
            $this->items = array_merge($this->items, $source->getItems());
        } catch (\Throwable $e) {
            // One source failing (e.g. a bot-check wall) shouldn't take down
            // the other; log and carry on with whatever we already have.
            $this->logger->warning(sprintf(
                'SearchAlertBridge: source %s failed: %s',
                $bridgeClass,
                $e->getMessage()
            ));
        }
    }

    private function dedupeItems(): void
    {
        $seen = [];
        $deduped = [];
        foreach ($this->items as $item) {
            $key = $item['uri'] ?? $item['title'] ?? null;
            if ($key !== null) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $deduped[] = $item;
        }
        $this->items = $deduped;
    }

    private function applyRequireExclude(): void
    {
        $require = $this->getInput('require');
        $exclude = $this->getInput('exclude');
        if (!$require && !$exclude) {
            return;
        }

        $flags = $this->getInput('case_insensitive') ? 'i' : '';
        $requireRegex = $require ? '#' . $require . '#' . $flags : null;
        $excludeRegex = $exclude ? '#' . $exclude . '#' . $flags : null;

        $this->items = array_values(array_filter($this->items, function ($item) use ($requireRegex, $excludeRegex) {
            $haystack = ($item['title'] ?? '') . ' ' . ($item['content'] ?? '') . ' ' . ($item['uri'] ?? '');
            if ($requireRegex && !preg_match($requireRegex, $haystack)) {
                return false;
            }
            if ($excludeRegex && preg_match($excludeRegex, $haystack)) {
                return false;
            }
            return true;
        }));
    }

    public function getURI()
    {
        if ($this->getInput('q')) {
            return 'https://www.startpage.com/sp/search?' . http_build_query(['query' => $this->getInput('q')]);
        }

        return parent::getURI();
    }

    public function getName()
    {
        if ($this->getInput('q')) {
            return $this->getInput('q') . ' - Search alert';
        }

        return parent::getName();
    }
}
