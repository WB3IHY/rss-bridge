<?php declare(strict_types=1);

class GoogleNewsSearchBridge extends FeedExpander
{
    const NAME = 'Google News search';
    const URI = 'https://news.google.com/';
    const DESCRIPTION = 'Returns results for a Google News search query, with an optional recency filter.';
    const MAINTAINER = 'WB3IHY';
    const CACHE_TIMEOUT = 1800; // 30m
    const PARAMETERS = [[
        'q' => [
            'name' => 'Keyword',
            'required' => true,
            'exampleValue' => 'rss-bridge',
        ],
        'when' => [
            'name' => 'Only show results from the last...',
            'type' => 'list',
            'values' => [
                'Any time' => '',
                'Hour' => '1h',
                'Day' => '1d',
                'Week' => '7d',
                'Month' => '30d',
                'Year' => '1y',
            ],
            'defaultValue' => '',
        ],
        'hl' => [
            'name' => 'Language (hl)',
            'required' => false,
            'defaultValue' => 'en-US',
            'title' => 'Google News edition language, e.g. en-US, en-GB, de',
        ],
        'gl' => [
            'name' => 'Country (gl)',
            'required' => false,
            'defaultValue' => 'US',
            'title' => 'Google News edition country, e.g. US, GB, DE',
        ],
    ]];

    public function collectData()
    {
        $this->collectExpandableDatas($this->getURI());
    }

    protected function parseItem(array $item)
    {
        // Google News reassigns the article ID embedded in <link>/<guid> for
        // what is otherwise the same story, which breaks read/unread dedup
        // in feed readers that key off it. The headline text is stable
        // across refetches, so hash that instead.
        $item['uid'] = hash('sha256', $item['title']);
        return $item;
    }

    public function getURI()
    {
        if ($this->getInput('q')) {
            $hl = $this->getInput('hl') ?: 'en-US';
            $gl = $this->getInput('gl') ?: 'US';
            $lang = explode('-', $hl)[0];

            $q = $this->getInput('q');
            $when = $this->getInput('when');
            if ($when) {
                $q .= ' when:' . $when;
            }

            $queryParameters = [
                'q' => $q,
                'hl' => $hl,
                'gl' => $gl,
                'ceid' => $gl . ':' . $lang,
            ];
            return sprintf('https://news.google.com/rss/search?%s', http_build_query($queryParameters));
        }

        return parent::getURI();
    }

    public function getName()
    {
        if ($this->getInput('q')) {
            return $this->getInput('q') . ' - Google News search';
        }

        return parent::getName();
    }
}
