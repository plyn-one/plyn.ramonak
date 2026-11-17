<?php
declare(strict_types=1);

namespace Ramonak\Media;

use Ramonak\Db;
use Ramonak\Jobs\Queue;

/**
 * Videos of YouTube in pages, so that a visitor meets YouTube only when they press play. An iframe of youtube.com (or of
 * youtube-nocookie.com) in a rendered page becomes a figure: a link to the video on YouTube, with its cover from the site's
 * own media library and its title, and the address of the player (youtube-nocookie.com) in data-video, which the site's
 * script puts in place on a click; without the script the link opens the video on YouTube. The cover and the title are
 * fetched once by the job video.cover, which the first page that shows the video queues.
 */
final class Videos
{
    private const IFRAME = '~<iframe\b[^>]*\bsrc="https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,20})[^"]*"[^>]*>\s*</iframe>~i';

    /** @param \Closure(string):?string $fetch the body of a URL, or null */
    public function __construct(private Db $db, private Queue $queue, private \Closure $fetch) {}

    public function rewrite(string $html, int $siteId, string $lang): string
    {
        // a paragraph that holds only the player is the figure itself: a figure cannot stand inside a p
        $html = (string) preg_replace('~<p>\s*(' . substr(self::IFRAME, 1, -2) . ')\s*</p>~i', '$1', $html);
        return (string) preg_replace_callback(self::IFRAME, function (array $m) use ($siteId, $lang): string {
            $id = $m[1];
            $row = $this->db->one("SELECT title, cover FROM video WHERE site_id = ? AND provider = 'youtube' AND video_id = ?", [$siteId, $id]);
            if ($row === null) {
                try {
                    $this->db->exec("INSERT INTO video (site_id, provider, video_id, title, cover, fetched_at) VALUES (?, 'youtube', ?, NULL, NULL, 0)", [$siteId, $id]);
                    $this->queue->push('video.cover', ['site_id' => $siteId, 'provider' => 'youtube', 'video_id' => $id]);
                } catch (\PDOException) {
                    // another request saw the video first and queued its cover
                }
                $row = ['title' => null, 'cover' => null];
            }
            preg_match('~\btitle="([^"]*)"~i', $m[0], $t);
            $title = (string) ($row['title'] ?? (isset($t[1]) && !preg_match('~^YouTube video player$~i', $t[1]) ? html_entity_decode($t[1]) : ''));
            $watch = ['ru' => 'Смотреть видео', 'be' => 'Глядзець відэа', 'en' => 'Watch the video'][$lang] ?? 'Watch the video';
            $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $cover = $row['cover'] ? '<img src="' . $e((string) $row['cover']) . '" alt="">' : '';
            return '<figure class="ramonak-video"><a href="https://www.youtube.com/watch?v=' . $id . '" data-video="https://www.youtube-nocookie.com/embed/' . $id
                . '?autoplay=1" aria-label="' . $e($watch . ($title !== '' ? ': ' . $title : '')) . '" rel="noopener">' . $cover
                . '<span class="ramonak-video-play" aria-hidden="true"></span></a>' . ($title !== '' ? '<figcaption>' . $e($title) . '</figcaption>' : '') . '</figure>';
        }, $html);
    }

    /**
     * The job video.cover: the title from YouTube's oEmbed and the largest cover it has, kept in the media library through
     * $add (media.add of the site). Returns what it found; a cover that cannot be fetched now is tried again by the queue.
     * @param \Closure(int, string, string):string $add site id, image bytes, alt → the path of the image in the library
     */
    public function cover(array $job, \Closure $add): string
    {
        [$site, $id] = [(int) $job['site_id'], (string) $job['video_id']];
        $meta = json_decode((string) ($this->fetch)('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id)), true);
        $title = is_array($meta) ? (string) ($meta['title'] ?? '') : '';
        $bytes = ($this->fetch)("https://i.ytimg.com/vi/{$id}/maxresdefault.jpg") ?? ($this->fetch)("https://i.ytimg.com/vi/{$id}/hqdefault.jpg");
        if ($bytes === null || $bytes === '') {
            throw new \RuntimeException("no cover of the video {$id} yet");
        }
        $path = $add($site, $bytes, $title);
        $this->db->exec("UPDATE video SET title = ?, cover = ?, fetched_at = ? WHERE site_id = ? AND provider = 'youtube' AND video_id = ?",
            [$title !== '' ? $title : null, $path, $this->db->now(), $site, $id]);
        return "video {$id}: {$path}";
    }
}
