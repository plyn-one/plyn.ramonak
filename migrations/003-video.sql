-- Videos of other services (YouTube) shown in pages: the title the service gives and the cover kept in the media library,
-- fetched once by the job video.cover, so that a visitor reaches the service only when they press play.
CREATE TABLE video (
  site_id {{REF}} NOT NULL REFERENCES site(id),
  provider VARCHAR(16) NOT NULL,
  video_id VARCHAR(64) NOT NULL,
  title {{TEXT}} NULL,
  cover {{KEY}} NULL,
  fetched_at BIGINT NOT NULL,
  PRIMARY KEY (site_id, provider, video_id)
) {{TABLE}};
