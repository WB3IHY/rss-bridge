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
            // Bare tag name only. This is still safe to GROUP by, since the grouping key
            // also includes the parent's identity (see walk()) - siblings under one <ul>
            // won't be conflated with unrelated <li>s elsewhere on the page. It's only
            // unsafe to EXPORT as a selector as-is, since CssSelectorComplexBridge would
            // apply it document-wide; evaluateGroup() scopes it via the parent before
            // exporting, or declines the candidate if no ancestor is identifiable.
            return $tag;
        }
        $classes = array_values(array_unique(array_filter(preg_split('/\s+/', $class))));
        sort($classes);
        return $tag . '.' . implode('.', $classes);
    }

    /**
     * Finds the nearest ancestor (starting at $node itself) with an id or class, and
     * returns a selector fragment for it. Used to scope an otherwise-bare tag selector
     * (e.g. a classless <li>) to the specific list it came from, rather than exporting
     * something that would match every <li> on the page.
     */
    private function findScopingAncestorSelector($node, int $maxDepth = 3): ?string
    {
        $depth = 0;
        while ($node !== null && $depth < $maxDepth) {
            if (!isset($node->tag) || $node->tag === 'root') {
                return null;
            }
            $id = trim((string) ($node->id ?? ''));
            if ($id !== '') {
                return '#' . $id;
            }
            $class = trim((string) ($node->class ?? ''));
            if ($class !== '') {
                $firstClass = preg_split('/\s+/', $class)[0];
                return $node->tag . '.' . $firstClass;
            }
            $node = $node->parent();
            $depth++;
        }
        return null;
    }

    /**
     * Entries often contain several links (upvote/react buttons, author,
     * tags, the title itself) before the real article link in DOM order —
     * e.g. a vote link that's identical across every entry. Picking "the
     * first link" is fooled by this; picking the link with the most visible
     * text is a much better proxy for "this is the title", since utility
     * links (vote counts, icons, short tags) are almost always shorter than
     * an actual headline.
     */
    private function findMostLikelyTitleLink($element)
    {
        $best = null;
        $bestLength = -1;
        foreach ($element->find('a') as $candidate) {
            if (empty($candidate->href)) {
                continue;
            }
            $length = mb_strlen(trim($candidate->plaintext));
            if ($length > $bestLength) {
                $bestLength = $length;
                $best = $candidate;
            }
        }
        return $best;
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
        $linkClassVotes = [];
        foreach ($elements as $element) {
            $link = $element->tag === 'a' ? $element : $this->findMostLikelyTitleLink($element);
            if ($link === null || empty($link->href)) {
                continue;
            }
            $href = html_entity_decode((string) $link->href);
            $urls[] = urljoin($pageUrl, $href);
            if (count($samples) < 3) {
                $samples[] = mb_substr(trim($element->plaintext), 0, 120);
            }

            $linkClass = trim((string) ($link->class ?? ''));
            if ($linkClass !== '') {
                $firstLinkClass = preg_split('/\s+/', $linkClass)[0];
                $linkClassVotes[$firstLinkClass] = ($linkClassVotes[$firstLinkClass] ?? 0) + 1;
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
        if (isset($parts[1])) {
            $entrySelector = $parts[0] . '.' . $parts[1];
        } else {
            // Bare tag signature (no class on the entry itself) - must be scoped to an
            // identifiable ancestor before it's safe to export, or it'll match every
            // occurrence of that tag on the whole page, not just this list.
            $scope = $this->findScopingAncestorSelector($group['parent']);
            if ($scope === null) {
                return null;
            }
            $entrySelector = $scope . ' ' . $parts[0];
        }

        // CssSelectorComplexBridge defaults its own url_selector to plain "a" (first
        // link in the entry) if we don't specify one — which reintroduces exactly the
        // "first link is a vote/react button" trap findMostLikelyTitleLink() exists to
        // avoid. So: if the links we actually picked mostly share a class, suggest that
        // as an explicit url_selector; otherwise leave it out and say so, rather than
        // silently handing back a config that looks fine but points at the wrong URL.
        $urlSelector = null;
        if ($linkClassVotes) {
            arsort($linkClassVotes);
            $topClass = array_key_first($linkClassVotes);
            $coverage = $linkClassVotes[$topClass] / $linkedCount;
            if ($coverage >= 0.6) {
                $urlSelector = 'a.' . $topClass;
            }
        }

        return [
            'entry_selector' => $entrySelector,
            'url_selector' => $urlSelector,
            'matchCount' => $count,
            'linkedCount' => $linkedCount,
            'distinctUrlCount' => $distinctUrls,
            'sampleTexts' => $samples,
            'score' => $score,
        ];
    }
}
