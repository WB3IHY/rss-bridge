<?php declare(strict_types=1);

class BingSearchBridge extends FeedExpander
{
    const NAME = 'Bing search';
    const URI = 'https://www.bing.com/';
    const DESCRIPTION = 'Returns the top 10 Bing web results for a search query, via Bing\'s own RSS output. Bing ignores date filters in RSS mode, so there is no recency option. Results that do not actually contain the keyword are dropped, because Bing pads rare queries with unrelated pages.';
    const MAINTAINER = 'WB3IHY';
    const CACHE_TIMEOUT = 1800; // 30m
    const PARAMETERS = [[
        'q' => [
            'name' => 'Keyword',
            'required' => true,
            'exampleValue' => 'rss-bridge',
        ],
    ]];

    public function collectData()
    {
        $this->collectExpandableDatas($this->getURI());

        // With no real match, Bing fills the page with fuzzy, unrelated results
        // instead of returning nothing. Keep only items that contain the
        // quoted phrase(s), or all the plain words if nothing is quoted.
        $terms = $this->requiredTerms($this->getInput('q'));
        $this->items = array_values(array_filter($this->items, function ($item) use ($terms) {
            $haystack = mb_strtolower(
                ($item['title'] ?? '') . ' ' . ($item['content'] ?? '') . ' ' . ($item['uri'] ?? '')
            );
            foreach ($terms as $term) {
                if (strpos($haystack, $term) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function requiredTerms(string $query): array
    {
        $terms = [];
        if (preg_match_all('/"([^"]+)"/', $query, $matches)) {
            $terms = $matches[1];
            $query = preg_replace('/"[^"]*"/', ' ', $query);
        }
        foreach (preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            // Skip operators (-word, site:x, OR/AND) and a lone unmatched quote
            if (!preg_match('/^(-|\w+:|OR$|AND$|NOT$)/', $word)) {
                $terms[] = trim($word, '"');
            }
        }
        return array_map('mb_strtolower', array_filter($terms, 'strlen'));
    }

    protected function parseItem(array $item)
    {
        // Bing's pubDate tracks its crawl of the page, so it moves around for
        // the same result; key read/unread dedup on the URL instead.
        $item['uid'] = hash('sha256', $item['uri']);
        return $item;
    }

    public function getURI()
    {
        if ($this->getInput('q')) {
            return sprintf('https://www.bing.com/search?%s', http_build_query([
                'q' => $this->getInput('q'),
                'format' => 'rss',
            ]));
        }

        return parent::getURI();
    }

    public function getName()
    {
        if ($this->getInput('q')) {
            return $this->getInput('q') . ' - Bing search';
        }

        return parent::getName();
    }
}
