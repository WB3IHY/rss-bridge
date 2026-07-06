<?php

declare(strict_types=1);

/**
 * Heuristically finds a repeated "article/entry" pattern on a listing page:
 * a group of sibling elements sharing the same tag+class signature, each
 * containing a distinct link. This is the scrape-based counterpart to native
 * feed autodiscovery (see DiscoverAction) — used when a site has no usable
 * feed, or its feed exists but isn't good enough.
 *
 * This is a best-effort heuristic over the page's static HTML, not a
 * guarantee. It only ever proposes a candidate CssSelectorComplexBridge
 * config for a human to verify and refine.
 */
class EntryClusterDetector
{
    private const MIN_GROUP_SIZE = 4;
    private const MAX_GROUP_SIZE = 100;

    // Class-name substrings that strongly suggest chrome/boilerplate, not article entries.
    private const DENYLIST_HINTS = [
        'nav', 'menu', 'footer', 'header', 'sidebar', 'widget', 'comment',
        'share', 'social', 'pagination', 'breadcrumb', 'cookie', 'banner',
        'advert', 'promo', 'newsletter',
    ];

    private const BOILERPLATE_ANCESTOR_TAGS = ['nav', 'header', 'footer', 'aside'];

    /**
     * @return array{entry_selector: string, matchCount: int, linkedCount: int, distinctUrlCount: int, sampleTexts: string[], score: float}|null
     */
    public function detect(\simple_html_dom $html, string $pageUrl): ?array
    {
        $groups = [];
        $this->walk($html->root, $groups);

        $best = null;
        $bestScore = 0.0;

        foreach ($groups as $group) {
            $candidate = $this->evaluateGroup($group, $pageUrl);
            if ($candidate !== null && $candidate['score'] > $bestScore) {
                $bestScore = $candidate['score'];
                $best = $candidate;
            }
        }

        return $best;
    }

    private function walk($node, array &$groups): void
    {
        if (!isset($node->tag) || in_array($node->tag, ['text', 'comment', 'script', 'style', 'root'], true)) {
            foreach ($node->children() as $child) {
                $this->walk($child, $groups);
            }
            return;
        }

        $parent = $node->parent();
        if ($parent !== null) {
            $signature = $this->signature($node);
            if ($signature !== null) {
                $key = spl_object_id($parent) . '|' . $signature;
                $groups[$key]['elements'][] = $node;
                $groups[$key]['signature'] = $signature;
                $groups[$key]['parent'] = $parent;
            }
        }

        foreach ($node->children() as $child) {
            $this->walk($child, $groups);
        }
    }

    private function signature($node): ?string
    {
        $tag = $node->tag;
        $class = trim((string) ($node->class ?? ''));
        if ($class === '') {
            return null; // too generic a signal on its own (tag name alone matches too much)
        }
        $classes = array_values(array_unique(array_filter(preg_split('/\s+/', $class))));
        sort($classes);
        return $tag . '.' . implode('.', $classes);
    }

    private function hasBoilerplateAncestor($node, int $maxDepth = 4): bool
    {
        $depth = 0;
        while ($node !== null && $depth < $maxDepth) {
            if (isset($node->tag) && in_array($node->tag, self::BOILERPLATE_ANCESTOR_TAGS, true)) {
                return true;
            }
            $node = $node->parent();
            $depth++;
        }
        return false;
    }

    private function evaluateGroup(array $group, string $pageUrl): ?array
    {
        $elements = $group['elements'];
        $count = count($elements);
        if ($count < self::MIN_GROUP_SIZE || $count > self::MAX_GROUP_SIZE) {
            return null;
        }

        $signature = $group['signature'];
        foreach (self::DENYLIST_HINTS as $hint) {
            if (stripos($signature, $hint) !== false) {
                return null;
            }
        }

        if ($this->hasBoilerplateAncestor($group['parent'])) {
            return null;
        }

        $urls = [];
        $samples = [];
        foreach ($elements as $element) {
            $link = $element->tag === 'a' ? $element : $element->find('a', 0);
            if ($link === null || empty($link->href)) {
                continue;
            }
            $href = html_entity_decode((string) $link->href);
            $urls[] = urljoin($pageUrl, $href);
            if (count($samples) < 3) {
                $samples[] = mb_substr(trim($element->plaintext), 0, 120);
            }
        }

        $linkedCount = count($urls);
        if ($linkedCount < self::MIN_GROUP_SIZE) {
            return null; // most elements in this group don't even contain a link
        }

        $distinctUrls = count(array_unique($urls));
        if ($distinctUrls < 2) {
            return null; // every "entry" points to the same place - not a real item list
        }

        $linkRatio = $linkedCount / $count;
        $distinctRatio = $distinctUrls / $linkedCount;
        $score = $count * $linkRatio * $distinctRatio;

        $parts = explode('.', $signature);
        $entrySelector = isset($parts[1]) ? $parts[0] . '.' . $parts[1] : $parts[0];

        return [
            'entry_selector' => $entrySelector,
            'matchCount' => $count,
            'linkedCount' => $linkedCount,
            'distinctUrlCount' => $distinctUrls,
            'sampleTexts' => $samples,
            'score' => $score,
        ];
    }
}
