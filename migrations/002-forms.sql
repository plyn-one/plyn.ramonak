-- Forms: what a visitor sent through a form a site declares, with the page and language it came from. The client is a
-- keyed hash of the visitor's address, kept only to limit how often one address may send.
CREATE TABLE form_entry (
  id {{ID}},
  site_id {{REF}} NOT NULL REFERENCES site(id),
  form VARCHAR(64) NOT NULL,
  lang VARCHAR(16) NOT NULL,
  page {{KEY}} NOT NULL,
  fields {{JSON}} NOT NULL,
  client VARCHAR(64) NOT NULL,
  state VARCHAR(16) NOT NULL DEFAULT 'new' CHECK (state IN ('new', 'sent', 'failed')),
  created_at BIGINT NOT NULL
) {{TABLE}};
CREATE INDEX form_entry_site ON form_entry (site_id, form, created_at);
CREATE INDEX form_entry_client ON form_entry (client, created_at);
