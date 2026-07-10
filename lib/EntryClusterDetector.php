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
    private const MAX_GROUP_SIZE = 500;

    // Fraction of a same-tag/same-parent-scope sibling pool that must share a class
    // for it to count as part of that pool's "core" signature (see buildCoreClassGroup).
    private const MIN_CORE_CLASS_COVERAGE = 0.6;

    // Class-name substrings that strongly suggest chrome/boilerplate, not article entries.
    private const DENYLIST_HINTS = [
        'nav', 'menu', 'footer', 'header', 'sidebar', 'widget', 'comment',
        'share', 'social', 'pagination', 'breadcrumb', 'cookie', 'banner',
        'advert', 'promo', 'newsletter',
    ];

    private const BOILERPLATE_ANCESTOR_TAGS = ['nav', 'header', 'footer', 'aside'];

    // Class-name substrings marking "this is rendered post/article body prose", not a
    // list of entries. Verified against ma.tt: its real per-post <article> elements each
    // wrap their prose in <div class="entry-content">, which itself contains many
    // <p class="wp-block-paragraph"> - some holding inline citation links (e.g. linking
    // out to a news article being quoted). Pooled across ~12 posts on the homepage, those
    // incidental paragraph links out-counted (59 elements) and out-scored the real,
    // correct 12-article group, since nothing previously distinguished "a link inside
    // ordinary body prose" from "a link that IS the entry". A candidate whose own parent
    // sits inside one of these wrappers is reliably body content, not a list of entries.
    private const BODY_CONTENT_ANCESTOR_HINTS = [
        'entry-content', 'post-content', 'article-content', 'article-body', 'post-body',
    ];

    /**
     * @return array{entry_selector: string, matchCount: int, linkedCount: int, distinctUrlCount: int, sampleTexts: string[], score: float}|null
     */
    public function detect(\simple_html_dom $html, string $pageUrl): ?array
    {
        $groups = [];
        $tagPools = [];
        $this->walk($html->root, $groups, $tagPools);

        foreach ($tagPools as $poolKey => $pool) {
            $coreGroup = $this->buildCoreClassGroup($pool);
            if ($coreGroup !== null) {
                $groups[$poolKey . '|core'] = $coreGroup;
            }
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($groups as $group) {
            $candidate = $this->evaluateGroup($group, $pageUrl, $html);
            if ($candidate !== null && $candidate['score'] > $bestScore) {
                $bestScore = $candidate['score'];
                $best = $candidate;
            }
        }

        return $best;
    }

    private function walk($node, array &$groups, array &$tagPools): void
    {
        if (!isset($node->tag) || in_array($node->tag, ['text', 'comment', 'script', 'style', 'root'], true)) {
            foreach ($node->children() as $child) {
                $this->walk($child, $groups, $tagPools);
            }
            return;
        }

        $parent = $node->parent();
        if ($parent !== null) {
            $signature = $this->signature($node);
            if ($signature !== null) {
                $scopeKey = $this->parentScopeKey($parent);
                $key = $scopeKey . '|' . $signature;
                $groups[$key]['elements'][] = $node;
                $groups[$key]['signature'] = $signature;
                // Groups pooled across several structurally-equivalent parents (see
                // parentScopeKey) end up with one representative parent here, from
                // whichever element happened to be visited last - fine for the ancestor
                // checks this is used for (boilerplate tags, scoping selector lookups),
                // since all pooled parents share the same signature by construction.
                $groups[$key]['parent'] = $parent;
                $groups[$key]['pooled'] = str_starts_with($scopeKey, 'scoped:');

                // Coarser sibling pool for buildCoreClassGroup(): same tag and parent
                // scope, but NOT keyed by the full class signature, so it survives even
                // when every sibling's exact class list differs (see that method).
                $poolKey = $scopeKey . '|tag:' . $node->tag;
                $tagPools[$poolKey]['elements'][] = $node;
                $tagPools[$poolKey]['parent'] = $parent;
                $tagPools[$poolKey]['tag'] = $node->tag;
                $tagPools[$poolKey]['pooled'] = str_starts_with($scopeKey, 'scoped:');
            }
        }

        foreach ($node->children() as $child) {
            $this->walk($child, $groups, $tagPools);
        }
    }

    /**
     * Fallback for siblings that share a tag and parent but never form a single
     * exact-match signature group large enough on its own (see signature() /
     * walk()) - most commonly WordPress's post_class() output, which stamps every
     * <article> with a unique per-post "post-{ID}" class plus that post's own
     * category/tag classes, on top of a shared set of theme/structural classes.
     * Exact full-class-list matching then sees N distinct one-off signatures
     * instead of one real group of N.
     *
     * Finds classes shared by at least MIN_CORE_CLASS_COVERAGE of the pool's
     * elements - not literally all of them, so a couple of genuine outliers (e.g.
     * a few posts with an extra has-post-thumbnail class the rest lack) don't
     * derail the whole group - then keeps only the elements containing every one
     * of those "core" classes.
     *
     * Deliberately conservative: needs an actual non-empty shared core to fire at
     * all. This is what keeps it from merging e.g. Hacker News's title rows
     * (tr.athing) and subtext rows (bare tr, same tag, same parent) into one
     * group: neither the ~46%-of-the-pool "athing" class nor "no class at all"
     * clears the coverage threshold, so the core comes back empty and this
     * contributes nothing there - the existing exact-match groups already produce
     * the right two candidates for that page anyway.
     *
     * @param array{elements: array, parent: mixed, tag: string, pooled: bool} $pool
     * @return array{elements: array, signature: string, parent: mixed, pooled: bool}|null
     */
    private function buildCoreClassGroup(array $pool): ?array
    {
        $elements = $pool['elements'];
        $count = count($elements);
        if ($count < self::MIN_GROUP_SIZE || $count > self::MAX_GROUP_SIZE) {
            return null;
        }

        $classCounts = [];
        foreach ($elements as $element) {
            $class = trim((string) ($element->class ?? ''));
            if ($class === '') {
                continue;
            }
            foreach (array_unique(preg_split('/\s+/', $class)) as $token) {
                $classCounts[$token] = ($classCounts[$token] ?? 0) + 1;
            }
        }

        $threshold = self::MIN_CORE_CLASS_COVERAGE * $count;
        $coreClasses = [];
        foreach ($classCounts as $token => $n) {
            if ($n >= $threshold) {
                $coreClasses[] = $token;
            }
        }

        if (!$coreClasses) {
            return null;
        }
        sort($coreClasses);

        $matching = [];
        foreach ($elements as $element) {
            $class = trim((string) ($element->class ?? ''));
            if ($class === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', $class);
            $hasAllCore = true;
            foreach ($coreClasses as $core) {
                if (!in_array($core, $tokens, true)) {
                    $hasAllCore = false;
                    break;
                }
            }
            if ($hasAllCore) {
                $matching[] = $element;
            }
        }

        if (count($matching) < self::MIN_GROUP_SIZE) {
            return null;
        }

        return [
            'elements' => $matching,
            'signature' => $pool['tag'] . '.' . implode('.', $coreClasses),
            'parent' => $pool['parent'],
            'pooled' => $pool['pooled'],
        ];
    }

    /**
     * Identifies "the logical container" a child belongs to for grouping purposes.
     * Normally that's just the parent's own object identity - the original behavior,
     * still used verbatim whenever the parent is a generic, classless wrapper.
     *
     * But when the parent itself has a class (a styled, deliberately-repeatable
     * component, not incidental markup) and has its own parent, pool by (grandparent,
     * parent's own signature) instead of the parent's exact identity. This is what lets
     * nbcphiladelphia.com's 9 separate "category" sections - each containing a handful
     * of story cards, too few individually to ever pass MIN_GROUP_SIZE on their own -
     * be recognized as one combined list of ~30 articles, since all 9 category divs
     * share both a class and a grandparent even though each has its own children.
     */
    private function parentScopeKey($parent): string
    {
        $parentClass = trim((string) ($parent->class ?? ''));
        $grandparent = $parent->parent();
        if ($parentClass !== '' && $grandparent !== null) {
            $parentSignature = $this->signature($parent);
            return 'scoped:' . spl_object_id($grandparent) . '|' . $parentSignature;
        }
        return 'exact:' . spl_object_id($parent);
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

    // Conventional "click through to the real article" link text. Verified against
    // lwn.net: its actual permalink is a short <a href="/Articles/.../">Full Story</a>,
    // while the blurb body often contains a much longer link to some unrelated external
    // page it happens to be discussing (e.g. a conference site) - the opposite problem
    // from the vote-button trap, where the correct link is the SHORT one, not the long one.
    private const CONTINUE_READING_PATTERNS = [
        'full story', 'read more', 'continue reading', 'keep reading',
        'read full article', 'read the full', 'permalink', '[link]',
    ];

    private const HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * Entries often contain several links (upvote/react buttons, author,
     * tags, the title itself) before the real article link in DOM order —
     * e.g. a vote link that's identical across every entry. Picking "the
     * first link" is fooled by this; picking the link with the most visible
     * text is a much better proxy for "this is the title", since utility
     * links (vote counts, icons, short tags) are almost always shorter than
     * an actual headline.
     *
     * Conventional "continue reading" links are checked first and win outright
     * regardless of length, since they're a stronger, more direct signal than
     * text length can ever be - text length is only a fallback proxy for
     * "this is probably the title", not the actual goal.
     *
     * A link wrapped in a heading tag (h1-h6) is checked next, also winning
     * outright over plain length among only the heading-wrapped candidates.
     * Verified against ma.tt: real posts there have a full prose body, and an
     * inline citation link within that body (e.g. "...writes: <a>Automattic's
     * 'Code for the People' Documentary Is a Rallying Cry...</a>") easily
     * outruns the actual title link's length (<h1 class="entry-title"><a>Code
     * for the People</a></h1>) - the opposite of the vote-button trap, where
     * length correctly favors the title. A heading wrapper is a much stronger,
     * more direct "this is the title" signal than length can ever be for
     * entries whose body text also contains links.
     */
    private function findMostLikelyTitleLink($element)
    {
        $best = null;
        $bestLength = -1;
        $headingBest = null;
        $headingBestLength = -1;
        foreach ($element->find('a') as $candidate) {
            if (empty($candidate->href)) {
                continue;
            }
            $text = trim($candidate->plaintext);
            if (in_array(strtolower($text), self::CONTINUE_READING_PATTERNS, true)) {
                return $candidate;
            }
            $length = mb_strlen($text);
            if ($length > $bestLength) {
                $bestLength = $length;
                $best = $candidate;
            }
            if ($this->isInsideHeading($candidate, $element) && $length > $headingBestLength) {
                $headingBestLength = $length;
                $headingBest = $candidate;
            }
        }
        return $headingBest ?? $best;
    }

    private function isInsideHeading($link, $entryBoundary, int $maxDepth = 6): bool
    {
        $node = $link->parent();
        $depth = 0;
        while ($node !== null && $node !== $entryBoundary && $depth < $maxDepth) {
            if (isset($node->tag) && in_array($node->tag, self::HEADING_TAGS, true)) {
                return true;
            }
            $node = $node->parent();
            $depth++;
        }
        return false;
    }

    /**
     * Builds a selector fragment (relative to the entry element) that should reach
     * $link specifically, not just "the first <a>". Prefers the link's own class;
     * if it has none (e.g. Hacker News's title/vote links are both classless),
     * walks up from the link - stopping at the entry boundary - looking for the
     * nearest wrapping element with a class to scope through instead (e.g. HN's
     * title link sits in <span class="titleline">, its vote link doesn't).
     */
    private function findLinkSelectorFragment($entryElement, $link): ?string
    {
        $linkClass = trim((string) ($link->class ?? ''));
        if ($linkClass !== '') {
            return 'a.' . preg_split('/\s+/', $linkClass)[0];
        }

        $node = $link->parent();
        $depth = 0;
        while ($node !== null && $node !== $entryElement && $depth < 5) {
            $class = trim((string) ($node->class ?? ''));
            if ($class !== '') {
                $firstClass = preg_split('/\s+/', $class)[0];
                return $node->tag . '.' . $firstClass . ' a';
            }
            $node = $node->parent();
            $depth++;
        }

        return null;
    }

    private function hasBoilerplateAncestor($node, int $maxDepth = 8): bool
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

    private function hasBodyContentAncestor($node, int $maxDepth = 4): bool
    {
        $depth = 0;
        while ($node !== null && $depth < $maxDepth) {
            if (isset($node->tag)) {
                $class = strtolower(trim((string) ($node->class ?? '')));
                if ($class !== '') {
                    foreach (self::BODY_CONTENT_ANCESTOR_HINTS as $hint) {
                        if (stripos($class, $hint) !== false) {
                            return true;
                        }
                    }
                }
            }
            $node = $node->parent();
            $depth++;
        }
        return false;
    }

    private function evaluateGroup(array $group, string $pageUrl, \simple_html_dom $html): ?array
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

        if ($this->hasBodyContentAncestor($group['parent'])) {
            return null;
        }

        $urls = [];
        $samples = [];
        $linkSelectorVotes = [];
        $linkTextLengths = [];
        $headingLinkCount = 0;
        foreach ($elements as $element) {
            $link = $element->tag === 'a' ? $element : $this->findMostLikelyTitleLink($element);
            if ($link === null || empty($link->href)) {
                continue;
            }
            $href = html_entity_decode((string) $link->href);
            $urls[] = urljoin($pageUrl, $href);
            $linkTextLengths[] = mb_strlen(trim($link->plaintext));
            if ($element->tag !== 'a' && $this->isInsideHeading($link, $element)) {
                $headingLinkCount++;
            }
            if (count($samples) < 3) {
                $samples[] = mb_substr(trim($element->plaintext), 0, 120);
            }

            $linkSelector = $this->findLinkSelectorFragment($element, $link);
            if ($linkSelector !== null) {
                $linkSelectorVotes[$linkSelector] = ($linkSelectorVotes[$linkSelector] ?? 0) + 1;
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

        // Verified against lwn.net: pooling (see parentScopeKey) correctly combines
        // nbcphiladelphia.com's fragmented-but-genuinely-repeated story cards, but it can
        // also combine heterogeneous, NOT-actually-repeated elements that just happen to
        // share a tag - e.g. every blurb's few different <p> tags (an intro paragraph, a
        // "Full Story" paragraph) pooled across all 10 blurbs inflated the raw count (50)
        // enough to outscore the correct, cleaner div.BlurbListing grouping (10/10 perfect
        // ratio), despite the pooled group's much worse distinctness (22/50). Pooled
        // candidates are structurally riskier than an exact shared parent, so hold them to
        // a higher distinctness bar rather than letting raw count alone win them the vote.
        if (!empty($group['pooled']) && ($distinctUrls / $linkedCount) < 0.8) {
            return null;
        }

        $avgLinkTextLength = array_sum($linkTextLengths) / count($linkTextLengths);
        // Verified against github.com/torvalds/linux/releases: when the real content
        // (release cards) isn't in the static HTML at all - genuinely client-rendered,
        // a case this static-HTML-only detector has no way to reach - the only
        // remaining repeated-link structure was GitHub's own repo nav tab bar
        // ("Code"/"Actions"/"Projects", avg ~6 chars). No amount of clustering logic
        // finds the real content if it isn't there; the right response is to decline
        // rather than confidently suggest chrome. Nav/menu labels are reliably short;
        // real article titles reliably aren't.
        //
        // Exception: a link wrapped in a heading tag (h1-h6) is direct structural
        // evidence it's a title regardless of length - verified against ma.tt, whose
        // real (correct) post titles include several genuinely short ones ("WCEU",
        // "Maybe", "USA 250"; avg ~13 chars across the page), which this length
        // floor would otherwise reject outright. Nav tabs are essentially never
        // wrapped in a heading tag, so this doesn't reopen the GitHub case.
        $mostlyHeadingLinks = $linkedCount > 0 && ($headingLinkCount / $linkedCount) >= 0.6;
        if ($avgLinkTextLength < 15 && !$mostlyHeadingLinks) {
            return null;
        }

        $linkRatio = $linkedCount / $count;
        $distinctRatio = $distinctUrls / $linkedCount;
        // Real article titles run much longer than metadata rows (vote counts,
        // "N comments", usernames, timestamps). Without this, a page like Hacker
        // News scores its classless subtext rows (points/author/time/comments -
        // each linking somewhere distinct) just as well as its actual title rows,
        // and may even win on raw count alone if there happen to be more of them.
        $score = $count * $linkRatio * $distinctRatio * log(1 + $avgLinkTextLength);

        $parts = explode('.', $signature);
        if (isset($parts[1])) {
            $entrySelector = $parts[0] . '.' . $parts[1];
        } else {
            // Bare tag signature (no class on the entry itself) - must be scoped to an
            // identifiable ancestor before it's safe to export, or it'll match every
            // occurrence of that tag on the whole page, not just this list.
            $scope = $this->findScopingAncestorSelector($group['parent']);
            if ($scope !== null) {
                $entrySelector = $scope . ' ' . $parts[0];
            } else {
                // No identifiable ancestor anywhere nearby (e.g. a page with no class or
                // id at all). Still safe to export the bare tag IF this group already
                // accounts for every occurrence of that tag on the whole page - there's
                // nothing else it could accidentally sweep in.
                if (count($html->find($parts[0])) !== $count) {
                    return null;
                }
                $entrySelector = $parts[0];
            }
        }

        // The denylist check above only covered the entry's own signature. A classless
        // entry's scope comes from an ancestor whose class was never checked - e.g. a
        // classless <li> scoped to "ul.NavDropdown-module__list__zuCgG" (GitHub) or
        // "ul.crawler-view-anon-menu" (Discourse) both slipped through as nav menus,
        // not article lists, before this check existed.
        foreach (self::DENYLIST_HINTS as $hint) {
            if (stripos($entrySelector, $hint) !== false) {
                return null;
            }
        }

        // CssSelectorComplexBridge defaults its own url_selector to plain "a" (first
        // link in the entry) if we don't specify one — which reintroduces exactly the
        // "first link is a vote/react button" trap findMostLikelyTitleLink() exists to
        // avoid. So: if the links we actually picked resolve to a consistent selector
        // fragment, suggest that as an explicit url_selector; otherwise leave it out and
        // say so, rather than silently handing back a config likely pointing at the wrong URL.
        $urlSelector = null;
        if ($linkSelectorVotes) {
            arsort($linkSelectorVotes);
            $topSelector = array_key_first($linkSelectorVotes);
            $coverage = $linkSelectorVotes[$topSelector] / $linkedCount;
            if ($coverage >= 0.6) {
                $urlSelector = $topSelector;
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
