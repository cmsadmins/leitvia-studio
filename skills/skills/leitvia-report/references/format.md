# Report format

Load this file when the skill names it. It holds layout, severity and wording for step 3 and 4 of [leitvia-report](../SKILL.md).

## Layout

```markdown
# Site report: <name of the site>

<address> · read on <date> · <scope, for example "all 34 published pages">

## Summary

Two to four sentences: how the site stands, then the most important points, at most five, each with what to do.

## Key figures

| Area | Figure | Source |
|---|---|---|
| Content checks | 12 findings on 7 of 34 pages, 3 high | leitvia/audit-site |
| Search visibility | indexable, sitemap and news sitemap found | leitvia/get-seo, /wp-sitemap.xml |
| Search data | 9 pages without description | leitvia/get-seo (Yoast SEO) |
| Updates | 2 plugins and the theme have updates | leitvia/get-site-health |
| Colors | all palette pairs pass | leitvia/get-tokens |
| Waiting changes | 1 package open since <date> | leitvia/list-packages |

## Findings

### Accessibility and content
### Search and AI answers

Start with visibility: whether search engines may index the site (`indexable`), the robots meta of the home page and which sitemaps answer. A live site that is hidden is a high finding.

### Versions and updates
### Design

Per finding: what, where (page title and address), why it matters for the reader, who fixes it.

## Decisions for you

Questions only the person can answer: indexing, legal texts, updates, packages to review.

## Not covered

What no ability reads: speed, security scans, backups, forms of other plugins, screen reader tests, legal review. Name what applies.

## Sources

Each ability and public URL with the date and time read, and whether it was complete.
```

Leave out an area with nothing to say rather than writing "no findings" five times; keep it in the key figures.

## Severity

| Level | Meaning | Examples |
|---|---|---|
| High | Visitors are blocked or misled, or the site is at risk | a live site hidden from search engines, images without alt text on main pages, a page with `noindex` that should be found, legal page placeholders, a pending update of WordPress, a PHP version without security support |
| Medium | Visitors are slowed or search results suffer | missing or duplicate descriptions, skipped heading levels, vague link texts, broken internal links, deprecated patterns |
| Low | Polish | long titles, a description slightly over length, classic content that still shows fine |

When unsure between two levels, take the lower one and say why.

## Wording

- Write for the reader of step 1. For an owner: "Seven images have no description for blind visitors", not "alt-text findings: 7".
- Numbers instead of adjectives: "9 of 34 pages", not "many pages".
- Every recommendation names who acts: the skill (for an agent run), a person with access to the backend, or the host.
- No praise, no alarm. State what is, what it means, what to do.
- No guarantees about rankings, AI answers, accessibility or the law.
