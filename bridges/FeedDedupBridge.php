<?php

class FeedDedupBridge extends FeedExpander
{
    const MAINTAINER = 'WB3IHY';
    const NAME = 'FeedDedup';
    const URI = 'https://github.com/RSS-Bridge/rss-bridge';
    const DESCRIPTION = <<<'TEXT'
        Keeps overlapping feeds separate but shows each item in only one of them. <br>
        Give every request the same ordered list of feeds, and set "Show feed number" to the feed to output. <br>
        An item that appears in several feeds is only shown in the first of them (lowest number). <br>
        Items with an identical url are considered duplicates. <br>
        If any feed fails to load, the whole request fails, so that items never end up in the wrong feed. <br>
    TEXT;

    const PARAMETERS = [
        [
            'show' => [
                'name' => 'Show feed number',
                'type' => 'number',
                'required' => true,
                'defaultValue' => 1,
                'exampleValue' => 1,
            ],
            'feed_name' => [
                'name' => 'Feed name',
                'type' => 'text',
                'exampleValue' => 'FeedDedup',
            ],
            'feed_1' => [
                'name' => 'Feed url',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://lorem-rss.herokuapp.com/feed?unit=day'
            ],
            'feed_2' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_3' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_4' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_5' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_6' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_7' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_8' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_9' => ['name' => 'Feed url', 'type' => 'text'],
            'feed_10' => ['name' => 'Feed url', 'type' => 'text'],
            'limit' => self::LIMIT,
        ]
    ];

    public function collectData()
    {
        $show = (int)$this->getInput('show');
        $limit = (int)($this->getInput('limit') ?: 99);

        // Keep the feed numbers (an empty slot does not shift the others)
        $feeds = [];
        for ($n = 1; $n <= 10; $n++) {
            $url = $this->getInput('feed_' . $n);
            if ($url) {
                $feeds[$n] = $url;
            }
        }

        if (!isset($feeds[$show])) {
            throw new \Exception(sprintf('Show feed number %d is not one of the feeds given', $show));
        }

        // Walk the feeds in order, and keep each url in the first feed that has it
        $seen = [];
        $mine = [];
        foreach ($feeds as $n => $feed) {
            $this->items = [];
            $this->collectExpandableDatas($feed);
            foreach ($this->items as $item) {
                $key = Url::fromString($item['uri'])->normalize();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($n === $show) {
                    $mine[] = $item;
                }
            }
        }

        usort($mine, function ($a, $b) {
            $t1 = $a['timestamp'] ?? 0;
            $t2 = $b['timestamp'] ?? 0;
            return $t2 <=> $t1;
        });

        $this->items = array_slice($mine, 0, $limit);
    }

    public function getIcon()
    {
        return 'https://cdn.jsdelivr.net/npm/famfamfam-silk@1.0.0/dist/png/folder_feed.png';
    }

    public function getName()
    {
        return $this->getInput('feed_name') ?: 'FeedDedup';
    }
}
