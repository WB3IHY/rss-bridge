<?php declare(strict_types=1);

class StartpageSearchBridge extends BridgeAbstract
{
    const NAME = 'Startpage search';
    const URI = 'https://www.startpage.com/';
    const DESCRIPTION = 'Returns results for a Startpage search query (Google results served through Startpage\'s privacy proxy), with an optional recency filter.';
    const MAINTAINER = 'WB3IHY';
    const CACHE_TIMEOUT = 1800; // 30m

    const PARAMETERS = [[
        'q' => [
            'name' => 'keyword',
            'required' => true,
            'exampleValue' => 'rss-bridge',
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
    ]];

    public function collectData()
    {
        $dom = getSimpleHTMLDOM($this->getURI(), ['Accept-language: en-US']);
        if (!$dom) {
            throwServerException('No results for this query.');
        }

        $titleLinks = $dom->find('a[data-testid=gl-title-link]');
        $descriptions = $dom->find('p[class~=description]');

        foreach ($titleLinks as $i => $titleLink) {
            $item = [];
            $item['uri'] = htmlspecialchars_decode($titleLink->href);

            $titleTag = $titleLink->find('h2', 0);
            $item['title'] = trim(html_entity_decode($titleTag ? $titleTag->plaintext : $titleLink->plaintext));

            $descText = isset($descriptions[$i]) ? trim(html_entity_decode($descriptions[$i]->plaintext)) : '';
            [$item['timestamp'], $item['content']] = $this->parseSnippetDate($descText);

            $this->items[] = $item;
        }

        // Sort by descending date; undated items sink to the bottom
        usort($this->items, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });
    }

    /**
     * Startpage prefixes dated snippets with either a relative date
     * ("3 days ago ... rest") or, for older results, an absolute one
     * ("Apr 4, 2024 ... rest"). Returns [timestamp, contentWithoutDatePrefix].
     */
    private function parseSnippetDate(string $descText): array
    {
        if (preg_match('/^(\d+)\s+(minute|hour|day|week|month|year)s?\s+ago\s*\.\.\.\s*(.*)$/is', $descText, $matches)) {
            $date = new \DateTime();
            $date->modify(sprintf('-%d %s', (int) $matches[1], strtolower($matches[2])));
            return [$date->format('U'), trim($matches[3])];
        }

        if (preg_match('/^Yesterday\s*\.\.\.\s*(.*)$/is', $descText, $matches)) {
            $date = new \DateTime('yesterday');
            return [$date->format('U'), trim($matches[1])];
        }

        if (preg_match('/^([A-Za-z]{3} \d{1,2}, \d{4})\s*\.\.\.\s*(.*)$/s', $descText, $matches)) {
            try {
                $date = new \DateTime($matches[1]);
                return [$date->format('U'), trim($matches[2])];
            } catch (\Exception $e) {
                return [0, $descText];
            }
        }

        return [0, $descText];
    }

    public function getURI()
    {
        if ($this->getInput('q')) {
            $queryParameters = [
                'query' => $this->getInput('q'),
            ];
            $when = $this->getInput('when');
            if ($when) {
                $queryParameters['with_date'] = $when;
            }
            return sprintf('https://www.startpage.com/sp/search?%s', http_build_query($queryParameters));
        }

        return parent::getURI();
    }

    public function getName()
    {
        if ($this->getInput('q')) {
            return $this->getInput('q') . ' - Startpage search';
        }

        return parent::getName();
    }
}
