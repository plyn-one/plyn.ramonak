-- The core of Ramonak: sites, their domains and languages, items and their immutable versions, publications as the only
-- public state, reviews, routes and redirects, media, the queue, the record of changes, and the cache of built pages.
CREATE TABLE site (
  id {{ID}},
  site_key {{KEY}} NOT NULL UNIQUE,
  default_language VARCHAR(16) NOT NULL,
  indexable SMALLINT NOT NULL DEFAULT 0,
  settings {{JSON}} NOT NULL
) {{TABLE}};

CREATE TABLE site_domain (
  domain {{KEY}} NOT NULL PRIMARY KEY,
  site_id {{REF}} NOT NULL REFERENCES site(id),
  role VARCHAR(16) NOT NULL CHECK (role IN ('primary', 'alias', 'staging'))
) {{TABLE}};

CREATE TABLE site_language (
  site_id {{REF}} NOT NULL REFERENCES site(id),
  lang VARCHAR(16) NOT NULL,
  prefix VARCHAR(32) NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  machine SMALLINT NOT NULL DEFAULT 1,
  PRIMARY KEY (site_id, lang)
) {{TABLE}};

CREATE TABLE item (
  id {{ID}},
  site_id {{REF}} NOT NULL REFERENCES site(id),
  kind VARCHAR(64) NOT NULL,
  item_key {{KEY}} NOT NULL,
  parent_id {{REF}} NULL REFERENCES item(id),
  position INTEGER NOT NULL DEFAULT 0,
  state VARCHAR(16) NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'archived')),
  created_at BIGINT NOT NULL,
  UNIQUE (site_id, item_key)
) {{TABLE}};
CREATE INDEX item_kind ON item (site_id, kind, state, position);

CREATE TABLE item_version (
  id {{ID}},
  item_id {{REF}} NOT NULL REFERENCES item(id),
  lang VARCHAR(16) NOT NULL,
  revision INTEGER NOT NULL,
  origin VARCHAR(16) NOT NULL CHECK (origin IN ('source', 'edited', 'machine')),
  source_version_id {{REF}} NULL REFERENCES item_version(id),
  fields {{JSON}} NOT NULL,
  author {{KEY}} NOT NULL,
  created_at BIGINT NOT NULL,
  UNIQUE (item_id, lang, revision)
) {{TABLE}};

CREATE TABLE publication (
  id {{ID}},
  item_id {{REF}} NOT NULL REFERENCES item(id),
  lang VARCHAR(16) NOT NULL,
  version_id {{REF}} NOT NULL REFERENCES item_version(id),
  replaces_id {{REF}} NULL REFERENCES publication(id),
  current SMALLINT NOT NULL DEFAULT 1,
  published_by {{KEY}} NOT NULL,
  published_at BIGINT NOT NULL
) {{TABLE}};
CREATE INDEX publication_current ON publication (item_id, lang, current);

CREATE TABLE review (
  id {{ID}},
  publication_id {{REF}} NOT NULL REFERENCES publication(id),
  verdict VARCHAR(16) NOT NULL CHECK (verdict IN ('ok_for_now', 'needs_work')),
  note {{TEXT}} NOT NULL,
  reviewer {{KEY}} NOT NULL,
  created_at BIGINT NOT NULL
) {{TABLE}};

CREATE TABLE route (
  id {{ID}},
  site_id {{REF}} NOT NULL REFERENCES site(id),
  path {{KEY}} NOT NULL,
  lang VARCHAR(16) NOT NULL,
  item_id {{REF}} NULL REFERENCES item(id),
  status SMALLINT NOT NULL DEFAULT 200 CHECK (status IN (200, 301, 302, 410)),
  target {{TEXT}} NULL,
  created_at BIGINT NOT NULL,
  UNIQUE (site_id, path)
) {{TABLE}};
CREATE INDEX route_item ON route (item_id, lang, status);

CREATE TABLE media (
  id {{ID}},
  site_id {{REF}} NOT NULL REFERENCES site(id),
  path {{KEY}} NOT NULL,
  mime VARCHAR(128) NOT NULL,
  bytes BIGINT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  width INTEGER NULL,
  height INTEGER NULL,
  created_at BIGINT NOT NULL,
  UNIQUE (site_id, path)
) {{TABLE}};

CREATE TABLE media_text (
  media_id {{REF}} NOT NULL REFERENCES media(id),
  lang VARCHAR(16) NOT NULL,
  alt {{TEXT}} NOT NULL,
  caption {{TEXT}} NULL,
  PRIMARY KEY (media_id, lang)
) {{TABLE}};

CREATE TABLE job (
  id {{ID}},
  kind VARCHAR(64) NOT NULL,
  payload {{JSON}} NOT NULL,
  state VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'running', 'done', 'failed')),
  attempts INTEGER NOT NULL DEFAULT 0,
  run_after BIGINT NOT NULL,
  locked_until BIGINT NULL,
  result {{TEXT}} NULL,
  created_at BIGINT NOT NULL
) {{TABLE}};
CREATE INDEX job_due ON job (state, run_after);

CREATE TABLE event (
  id {{ID}},
  site_id {{REF}} NULL,
  actor {{KEY}} NOT NULL,
  operation VARCHAR(64) NOT NULL,
  subject {{KEY}} NULL,
  data {{JSON}} NOT NULL,
  created_at BIGINT NOT NULL
) {{TABLE}};

CREATE TABLE built_page (
  site_id {{REF}} NOT NULL REFERENCES site(id),
  host {{KEY}} NOT NULL,
  path {{KEY}} NOT NULL,
  file {{TEXT}} NOT NULL,
  built_at BIGINT NOT NULL,
  PRIMARY KEY (host, path)
) {{TABLE}};

-- What a built page was made from: item:<id> for an item it shows, kind:<site>:<kind> for a list of a kind, so that a
-- publication removes exactly the pages it changes.
CREATE TABLE built_page_dep (
  host {{KEY}} NOT NULL,
  path {{KEY}} NOT NULL,
  dep VARCHAR(96) NOT NULL,
  PRIMARY KEY (host, path, dep)
) {{TABLE}};
CREATE INDEX built_page_dep_dep ON built_page_dep (dep);

CREATE TABLE bridge_nonce (
  nonce VARCHAR(64) NOT NULL PRIMARY KEY,
  seen_at BIGINT NOT NULL
) {{TABLE}};
