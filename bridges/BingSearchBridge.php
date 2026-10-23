<?php declare(strict_types=1);

class BingSearchBridge extends FeedExpander
{
    const NAME = 'Bing search';
    const URI = 'https://www.bing.com/';
    const DESCRIPTION = 'Returns the top 10 Bing web results for a search query, via Bing\'s own RSS output. Bing ignores date filters in RSS mode, so there is no recency option.';
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
