# Ramonak: architecture

Ramonak is a web engine for sites that agents build and keep. A one-page site takes only the kernel and the modules it needs;
a large application adds modules without rewriting the kernel. This document fixes the decisions of the first slice, which
serves the new michalevic.org, and the seams that let the engine grow past it. What changes here is decided in the journal
(`śled.toml`) first.

## 1. What is inside and what is not

Inside: the kernel, the modules, their contracts, the data model, the operations, rendering and the SEO contract, the
queue, releases. Outside, and never in this repository:

- a site's kinds of content, templates, theme and settings: in its owner's repository (for Legal Status,
  `Legal-Status-UAB/sites/<site>/`);
- a site's content: in its database on its hosting;
- keys and roles: in the Klučnik of the owner's frame;
- page layout: `plyn-one/plyn.prastora`, linked by each site.

## 2. Runtime

- PHP 8.2 or later, no framework. Composer for a few libraries, each chosen for one job: Twig for templates, a router,
  a TOML reader. The whole runtime is a release archive with its `vendor/`; the host needs no Composer.
- MariaDB 10.6+ (11.4 on the first host) in production; SQLite for tests and local work. Every query goes through the
  data layer (section 4), so both run the same code; SQL that only one of them speaks lives in one adapter.
- Twig because agents know it well, it escapes by default, and a sandbox can confine a template edited from content.
  Templates are compiled and cached per release.
- No JavaScript is required to read a page. A module may add a script for an interaction, never for content.

## 3. Kernel and modules

The kernel does five things: it reads the configuration of the installation and its sites, composes the active modules,
resolves a request to a site, a language and a route, runs one operation or one rendering, and records what happened.
Everything else is a module.

A module declares itself in `module.php`, returning a plain array:

| key | what it declares |
|---|---|
| `name`, `version`, `requires` | identity and the modules (with versions) it needs |
| `tables` | migrations it owns, under its own prefix |
| `operations` | the operations it provides (section 5) |
| `routes` | URL patterns it serves, each exclusive or a fallback |
| `templates` | its Twig namespace |
| `jobs` | kinds of background job it handles |
| `extends` | extension points of other modules it contributes to, with an order |
| `provides` | extension points it opens to others, with how contributions combine |

The registry composes the declarations at start (cached per release) and refuses to start when two modules claim one
exclusive route, table prefix, operation or job kind, or when a requirement is missing. No module overrides another
silently, and none reaches into another's tables: it calls the other's operations or uses an extension point.

Modules of the first slice: `core` (sites, languages, routing, rendering, SEO), `content`, `publication`, `translation`,
`redirects`, `media`, `forms`, `jobs`, `bridge` (operations over HTTP for Klučnik), `release`.

## 4. Data

A site's content is in its database. The model is relational and indexed for the reads a page makes; flexible fields of a
kind live in one JSON column of a version, checked against the kind's schema when written.

| table | holds |
|---|---|
| `site` | a site of the installation: key, default language, settings |
| `site_domain` | its domains: primary, alias (301 to primary), staging |
| `site_language` | its languages: tag, path prefix (may be empty), order, whether machine translation is on |
| `item` | one thing of the site independent of language: kind, parent, position, state |
| `item_version` | an item in one language at one revision: fields, origin (`source`, `edited`, `machine`), the source version it was made from, author, time |
| `publication` | which version of an item in a language is public: who published (agent or person), when, which publication it replaced |
| `review` | what a person said of a publication: `ok_for_now` or `needs_work`, a note, who and when |
| `route` | a path of a site in a language: to an item, or a redirect (301, 302, 410) to a path or URL |
| `media`, `media_text` | files with checksum and type; alt text and caption per language |
| `form_entry` | enquiries, with the page and language they came from |
| `job` | the queue: kind, payload, state, attempts, run after, lock |
| `event` | every operation that changed something: actor, operation, subject, before and after references |

Rules held by construction, not by reminders:

- A version is never changed after it is written; an edit is a new version.
- What is public is only what a `publication` row points to; rendering reads publications, never drafts. Rollback is a new
  publication of an earlier version.
- A machine version is written only by the translation job; an `edited` version of the same item and language makes later
  machine versions stop for it. When the source gets a newer version, an edited translation is marked stale, not replaced.
- A route's path is unique per site; changing an item's path keeps the old path as a 301 to the new one.

The kinds of content of a site (their fields, which are translatable, their template), its domains and languages are
declared in the site's directory, `site.json`, which `bin/ramonak site:sync <site>` writes into the database and the
engine keeps compiled as PHP for OPcache. A new kind needs no migration.

## 5. Operations

An operation is a PHP class with a name (`content.save`), an input schema, the permission it needs and one method. The same
operations serve agents, a later administration interface and an API; none of them has logic of its own.

First slice: `site.status`, `content.list`, `content.read`, `content.save` (a new version), `publication.publish`,
`publication.rollback`, `review.add`, `route.set`, `redirect.set`, `redirect.import`, `redirect.delete`, `content.delete`, `media.add`, `translation.request`,
`form.list`, `form.delete`, `cache.purge`, `job.list`.

Forms: a site declares its forms in `site.json` under `forms` (fields with type, limit and label per language, the
addresses to notify, a trap field for robots). A form posts to `/_ramonak/form/<name>` from the site's own pages; the entry
is checked, kept in `form_entry` and mailed by the job `form.notify` through the mail of the installation (`mail` in
`shared/config.php`: the host's sendmail or a mail server), and the visitor returns to the page with `#<name>-sent` or
`#<name>-error`, so built pages stay the same for everyone. A form's `keep_days` bounds how long its entries are kept: `jobs:run`
deletes older ones.

Every operation that changes something writes an `event` in the same transaction.

## 6. Agents and people: through Klučnik

Agents do not log in to a site. The Klučnik of the owner's frame gets a tool `site`; the site and Klučnik share a bridge
key (in the installation's `shared/config.php` and in Klučnik's secrets). Klučnik signs each request to
`POST /_ramonak/api` (HMAC over method, host, path, time, a nonce, the actor and the body; `src/Bridge/Bridge.php`) and
adds who is acting (member id, level, agent); the site refuses a request older than five minutes or a nonce it has seen,
and maps the level to its permissions:

| level in Klučnik | may |
|---|---|
| administratar | everything, releases included |
| davierany | content, publication, rollback, routes, redirects, media, reviews |
| supracoŭnik | read, save drafts, add reviews |

An agent checks its work and publishes; a person reviews whenever they like, through their agent or later in an
administration interface built on the same operations. No acceptance is final: a reviewed publication can be replaced
like any other.

## 7. Rendering and the SEO contract

A request is resolved: domain to site (an alias redirects to the primary domain), path prefix to language, path to route.
A site may give every language its prefix (`/ru/…`, `/en/…`); then its root `/` sends a visitor (302, `Vary:
Accept-Language`, never built as a file) to the first of their browser's languages it has, else to its default language,
which a search engine also gets; the home pages name the root as `x-default`, other pages the version in the default
language. Old addresses without a prefix are kept as 301s to their new pages.
A route to an item renders its published version with the kind's template; a redirect answers with its code; nothing
found answers 404 with the site's page for it.

Every page, by default and without a module of its own:

- one `<main>` region with the content;
- `<title>`, meta description, canonical URL, `hreflang` alternates for each language with a publication, and
  `x-default`;
- correct status codes, HEAD, and no trailing-slash or case duplicates (one form, the other 301);
- `sitemap.xml` per site and language from publications; `robots.txt` per site;
- on any installation marked experimental: `X-Robots-Tag: noindex, nofollow` on every response and an empty sitemap.
  Indexing is switched on per site, by an administrator.

A published page is written on its first request as a file under `public/_cache/<host><path>/index.html`, which the
web server then sends by itself (`public/.htaccess`, written by `bin/ramonak htaccess`): measured on Apache with the
example site, 0.38 ms at the median against 1.27 ms rendered by PHP. Each built page records what it was made from
(an item, or a list of a kind); a publication, a move or a rollback removes exactly those pages, and the next request
builds them again. A release starts with an empty cache. The same measurement on the hosting (A-Rozum/rudy, October 2026)
gave 0.49 ms for a file and 4 to 5 ms for a page rendered from the database, which is why built pages come first.

Structured data (JSON-LD): the organization of the site (site.json `schema.organization`, such as LegalService) on its
home pages, and the type a kind declares (`schema`: Article, NewsArticle, BlogPosting) with headline, author, dates and
publisher. The dates a reader and a search engine see are the material's own: `date`, the date it belongs to (of the
interview, of the original article, of the post it was in), and `updated`, set only when it was revised in substance and
shown as such; without it dateModified is `date`. Moving a material between sites or fixing a typo dates nothing. When it
reached this server is an internal record of publications, for rollback and for who did what, and only the sitemap's
lastmod, read by crawlers, follows it. A publication can be scheduled (`publication.publish` with `at`, or
`publication.schedule`): the job publish runs it within a minute of its time. Every publication, move and removal tells
the engines of IndexNow (Bing, Yandex and others) of its addresses; the site serves its key at `/<key>.txt`. One scheme
(https) and one case (lower) answer every address; the others are 301s.

robots.txt carries Yandex's Clean-param for campaign parameters and blocks no AI crawler; responses carry HSTS (not on an
experimental installation), nosniff, SAMEORIGIN and a referrer policy; the web server compresses text and gives
versioned assets a year of cache. Open Graph and Twitter cards come with the head. The operation `seo.audit` lists what a
search engine would find wrong in what is published: pages no text links to, links that lead nowhere or through a
redirect, missing, duplicate or overlong titles and descriptions, missing dates, machine translations waiting for review.

Left out on purpose, as outdated or of no effect: the Host and Crawl-delay directives of robots.txt (Yandex reads 301s
and its Webmaster settings), Yandex's Original texts (closed), keyword density and the keywords meta tag, a canonical of
later list pages to the first (each page of a list is its own canonical), FAQ markup for rich results (ended on 7 May
2026), llms.txt and AI-specific markup.

## 8. Translation

When a source version is published, a `translation` job is queued for each language of the site with machine translation
on, unless that language has an edited version that follows a newer source. A machine version is public to visitors at once but closed to search engines (noindex, out of hreflang and the
sitemap) until as many distinct reviewers as the site asks (`machine_reviews`, 1 by default; 0 opens it at once) have
reviewed it as `ok_for_now` and none as `needs_work`. The job asks the model route of the frame's
Klučnik (its limit and accounting apply), writes a `machine` version and publishes it. The site's glossary of terms is kept in
its declaration by direction, `glossary.<from>.<to>`, a list of source and target pairs, since one site has sources in
several languages; for Legal Status it is generated from `Legal-Status-UAB/translation` (tools/term.py glossary).

## 9. Queue

Jobs run from cron every minute through `bin/ramonak jobs:run`, each with a lock and a time limit, retried with backoff and
left `failed` with its reason after the last attempt. Nothing slow runs while a page is being served.

## 10. Releases

A release is an archive built from a checked commit (code, `vendor/`, compiled assets). It is uploaded into
`releases/<commit>/` on the host, its migrations run, then the `current` symlink is switched in one step; the previous
release stays for rollback. Secrets and the database settings live in `shared/`, outside every release and outside the
document root. Upload and switch go through the hosting connection of the frame's Klučnik; the first installation is the
same steps done once by an administrator's agent.

## 11. Growing

What the first slice keeps open, so that a large application does not need another engine:

- every read and write through the data layer and operations: a cache, read replicas or another store go in behind them;
- the queue as a seam: jobs can move to a separate worker or service;
- modules as the unit of growth: search, accounts, payments, a catalogue, an API for others are modules with the same
  contracts;
- the host as configuration: nothing depends on shared hosting; a VPS or containers run the same release.

Nothing of these is built before a site needs it.
