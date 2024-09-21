<?php

namespace Tweetledummer;

use cjrasmussen\BlueskyApi\BlueskyApi;

class TweetledummerBluesky {

    const DATE_FORMAT_DISPLAY = 'M j, Y \a\t g:i A';

    const DATE_FORMAT_DB = 'Y-m-d H:i:s';

    const LINK_MAX_LENGTH = 60;

    const ELLIPSIS = '…';

    private $settings = [];

    public $blueskyUsername = '';

    private $blueskyApi;

    public $blueskyRefreshToken = NULL;

    public $blueskyIsAuthed = FALSE;

    private $db;

    public function __construct($settings) {
        $this->settings = $settings;
        $this->blueskyApi = new BlueskyApi();
        try {
            $this->blueskyUsername = $settings['bluesky']['username'];
            $this->db = new \mysqli($settings['database']['host'],
                $settings['database']['user'],
                $settings['database']['password'],
                $settings['database']['database']);
            $this->db->set_charset($settings['database']['charset']);
            $this->getBlueskyRefreshToken();
            if ($this->db->connect_errno > 0) {
                die('Unable to connect to database [' . $this->db->connect_error . ']');
            }
        } catch (\Exception $e) {
            // TODO: Handle the exception however you want
            print $e->getCode() . ': ' . $e->getMessage();
        }
    }

    private function getBlueskyAuth($retry = TRUE) {
        try {
            if ($this->blueskyRefreshToken && $retry) {
                $used_refresh_token = TRUE;
                $this->blueskyApi->auth($this->blueskyRefreshToken);
            }
            else {
                $used_refresh_token = FALSE;
                $this->blueskyApi->auth($this->settings['bluesky']['username'], $this->settings['bluesky']['app_password']);
            }
            $this->blueskyIsAuthed = TRUE;
            $this->setBlueskyRefreshToken($this->blueskyApi->getRefreshToken());
        } catch (\Exception $e) {
            // TODO: Handle the exception however you want
            if ($used_refresh_token) {
                // Try again without the refresh token.
                $this->getBlueskyAuth(FALSE);
            }
            else {
                print $e->getCode() . ': ' . $e->getMessage();
            }
        }
    }

    private function setBlueskyRefreshToken($refresh_token) {
        $sql = "REPLACE INTO tweetledummer_key_value
            (`key`, `value`, `timestamp`)
            VALUES
            (?, ?, ?) ";
        try {
            $query = $this->db->prepare($sql);
            $key = 'bluesky_refresh_token:' . $this->blueskyUsername;
            $timestamp = time();
            $query->bind_param('ssi',
                $key,
                $refresh_token,
                $timestamp);
            $query->execute();
            $this->blueskyRefreshToken = $refresh_token;
        } catch (\Exception $e) {
            print $sql . '<br>' . $e->getCode() . ': ' . $e->getMessage();
        }
        $query->close();
    }

    private function getBlueskyRefreshToken() {
        if (!$this->blueskyRefreshToken) {
            $sql = "SELECT `value`
              FROM tweetledummer_key_value
              WHERE `key` = ? ";
            $query = $this->db->prepare($sql);
            $key = 'bluesky_refresh_token:' . $this->blueskyUsername;
            $query->bind_param('s', $key);
            $query->execute();
            $this->blueskyRefreshToken = $query->get_result()->fetch_object()->value ?? '';
        }
        return $this->blueskyRefreshToken;
    }

    public function getProfile($actor = NULL) {
        if (!$this->blueskyIsAuthed) {
            $this->getBlueskyAuth();
        }
        if (!$actor) {
            $actor = $this->blueskyUsername;
        }
        $args = [
            'actor' => $actor,
        ];
        return $this->blueskyApi->request('GET', 'app.bsky.actor.getProfile', $args);
    }

    public function getTimeline($args = [], $since = FALSE) {

        static $posts = [];

        if (!$this->blueskyIsAuthed) {
            $this->getBlueskyAuth();
        }

        $args += [
            'collection' => 'app.bsky.feed.getTimeline',
//            'algorithm' => 'xxx',
//            'limit' => 10,
//            'cursor' => '2024-09-02T15:06:08.757Z',
        ];
        $result = $this->blueskyApi->request('GET', 'app.bsky.feed.getTimeline', $args);
        if (!isset($result->feed)) {
            return [];
        }

        $found = FALSE;
        foreach ($result->feed as $item) {
            $data = $this->getPostData($item);
            $data['full'] = $item;
            $posts[] = $data;

//            print "'{$data['id']}' < '{$since}' ????<br>\n";

            if ($since !== FALSE && strnatcmp($data['id'], $since) <= 0) {
//                print "*** EXITING: '{$data['id']}' < '{$since}'<br><br>\n";
                $found = TRUE;
                break;
            }
        } // Lop thru posts.

        $num_posts = count($posts);
        $cursor = $result->cursor ?? NULL;

        if ($num_posts > 500) {
            $cursor = FALSE;
        }

        if ($since !== FALSE && !$found && $cursor) {
            $args['cursor'] = $cursor;
//            print "*** CALL AGAIN: cursor='{$cursor}'<br><br>\n";
            $this->getTimeline($args, $since);
        }

        return $posts;

    }

    public function getPostData($item, $is_reply = FALSE) {
        $post = (!empty($item->post)) ? $item->post : $item;
        //$tz = date_default_timezone_get();
        $utc = new \DateTimeZone('UTC');
        $tz = new \DateTimeZone('America/New_York');
        $created_at = $post->record->createdAt ?? ($post->value->createdAt ?? NULL);
        $indexed_at = $post->indexedAt ?? NULL;
        $created_at_timestamp = ($created_at) ? strtotime($created_at) : time();
        $indexed_at_timestamp = ($indexed_at) ? strtotime($indexed_at) : time();
        $timestamp = min($created_at_timestamp, $indexed_at_timestamp);
        if ($timestamp !== FALSE) {
            $date = new \DateTime('@' . $timestamp, $utc);
            $date->setTimezone($tz);
            $created = $date->format(self::DATE_FORMAT_DISPLAY);
            //$created = $date->format(self::DATE_FORMAT_DB);
        } else {
            $created = '???';
        }
        $post_id = $this->getPostIdFromUri($post->uri);
        $data = [
            'id' => NULL,
            'post_id' => $post_id,
            'created_at' => $created_at,
            'uri' => $post->uri,
            'cid' => $post->cid ?? '',
            'post_url' => $this->getPostUrl($post),
            'text' => $post->record->text ?? ($post->value->text ?? NULL),
            'author_did' => $post->author->did ?? '',
            'author_handle' => $post->author->handle ?? '',
            'author_display_name' => $post->author->displayName ?? ($post->author->handle ?? ''),
            'author_avatar' => $post->author->avatar ?? '',
            'author_url' => $this->getAuthorUrl($post->author ?? NULL),
            'link_embed' => $post->record->embed->external->uri ?? NULL,
            'link_facet' => $post->record->facets[0]->features[0]->uri ?? NULL,
            'embed' => [],
            'images' => [],
            'video' => [],
            'created' => $created,
            'timestamp' => $timestamp,
            'repost_count' => $post->repostCount ?? 0,
            'like_count' => $post->likeCount ?? 0,
            'reply_count' => $post->replyCount ?? 0,
            'quote_count' => $post->quoteCount ?? 0,
            'repost' => [],
            'quoted' => [],
            'reply_to' => [],
        ];
        $embed_type = $post->embed->{'$type'} ?? NULL;
        if (!empty($post->record->embed->external->uri)) {
            $data['embed'] = [
                'uri' => $post->record->embed->external->uri,
                'title' => $post->record->embed->external->title,
                'description' => $post->record->embed->external->description,
                'thumb' => NULL,
            ];
            if (!empty($post->record->embed->external->thumb->ref->{'$link'})) {
                $mime_type = explode('/', $post->record->embed->external->thumb->mimeType)[1] ?? '';
                $data['embed']['thumb'] = 'https://cdn.bsky.app/img/feed_thumbnail/plain/' . $data['author_did'] . '/' . $post->record->embed->external->thumb->ref->{'$link'} . '@' . $mime_type;
            }
        }
        $images = $post->record->embed->images ?? $post->record->embed->media->images ?? $post->embeds[0]->images ?? NULL;
        if ($images) {
//            \Kint::dump($post->record->embed->images);
            foreach ($images as $image) {
                if (!empty($image->thumb)) {
                    $url = $image->thumb;
                }
                else {
                    $mime_type = explode('/', $image->image->mimeType)[1] ?? '';
                    $url = 'https://cdn.bsky.app/img/feed_thumbnail/plain/' . $data['author_did'] . '/' . $image->image->ref->{'$link'} . '@' . $mime_type;
                }
                $data['images'][] = [
                    'url' => $url,
                    'alt' => $image->alt ?? '',
                ];
            }
        }
        if ($embed_type == 'app.bsky.embed.video#view') {
            $data['video'] = [
                'thumbnail' => $post->embed->thumbnail,
                'playlist' => $post->embed->playlist,
            ];
        }
        $reason_type = $item->reason->{'$type'} ?? NULL;
        if ($reason_type == 'app.bsky.feed.defs#reasonRepost') {
            $data['repost'] = [
                'author_handle' => $item->reason->by->handle,
                'author_display_name' => $item->reason->by->displayName,
                'author_url' => $this->getAuthorUrl($item->reason->by),
            ];
            $created_at = $item->reason->indexedAt;
        }
        if (!$is_reply) {
            if (!empty($post->embed->record->cid)) {
                $data['quoted_record'] = $post->embed->record;
                //$post->embed->record->value->{'$type'} = 'app.bsky.feed.post';
                $data['quoted'] = $this->getPostData($post->embed->record, TRUE);
            } elseif (!empty($item->reply->parent)) {
                $data['reply_to'] = $this->getPostData($item->reply->parent, TRUE);
            }
        }
        $data['id'] = $this->getUniqueID($created_at, $post_id);
        return $data;
    }

    public function getUniqueID($created_at, $post_id) {
        $timestamp = preg_replace('/[^0-9]/', '', $created_at);
        $timestamp = str_pad($timestamp, 20, '0', STR_PAD_RIGHT);
        $post_id = str_pad($post_id, 20, '0', STR_PAD_LEFT);
        return $timestamp . '-' . $post_id;
    }

    public function getPostIdFromUri($uri) {
        // URI =at://did:plc:qpqyay6mqatzyyet3jdgxiwi/app.bsky.feed.post/3l37anceghm2z
        $parts = explode('/', $uri);
        $post_id = $parts[count($parts) - 1] ?? NULL;
        return $post_id;
    }

    public function getPostUrlFromUri($uri) {
        // URI =at://did:plc:qpqyay6mqatzyyet3jdgxiwi/app.bsky.feed.post/3l37anceghm2z
        // URL =https://bsky.app/profile/did:plc:qpqyay6mqatzyyet3jdgxiwi/post/3l37anceghm2z
        $parts = explode('/', $uri);
        $author = $parts[count($parts) - 3] ?? '';
        $post_id = $parts[count($parts) - 1] ?? '';
        return 'https://bsky.app/profile/' . $author . '/post/' . $post_id;
    }

    public function getPostUrl($post) {
//        https://bsky.app/profile/{$post['author']['did']}/post/{$post['post_id']
        if (empty($post->author->did) || empty($post->uri)) {
            return '';
        }
        $url = 'https://bsky.app/profile/' . $post->author->did . '/post/' . $this->getPostIdFromUri($post->uri);
        return $url;
    }

    public function getAuthorUrl($post_author) {
        // https://bsky.app/profile/{$post['author']['did']}
        if (empty($post_author->did)) {
            return '';
        }
        $url = 'https://bsky.app/profile/' . $post_author->did;
        return $url;
    }

    public function showPost($post, $class = '', $author = NULL, $body = NUll, $stats = NULL) {
        foreach (['author_display_name', 'author_handle', 'text'] as $key) {
            $post[$key] = htmlentities($post[$key]);
        }
        if (!$post['author_avatar']) {
            $post['author_avatar'] = 'images/circle-user-regular.svg';
        }
        $post['text'] = nl2br($post['text']);
        foreach (['like', 'repost', 'reply'] as $type) {
            $post[$type . '_count'] = (!empty($post[$type . '_count'])) ?: '';
            if ($post[$type . '_count'] > 1000) {
                $post[$type . '_count'] = number_format($post[$type . '_count']/1000, 1) . 'K';
            }
            $post[$type . '_count'] = '<div class="tweetledummer-post__stat tweetledummer-post__' . $type . '">'
                . '<img width=20" height="20" src="images/' . $type . '.svg">'
                . '<div>' . $post[$type . '_count'] . '</div>'
                . '</div>';
        }
        $is_video = '';
        $images = '';
        if (!empty($post['video'])) {
            $is_video = '<div class="tweetledummer-post__video"><img width=20" height="20" src="images/circle-play-regular.svg"><span>Video</span></div>';
            $post['images'][] = [
                'alt' => 'Video thumbnail',
                'url' => $post['video']['thumbnail'],
                'class' => 'video',
            ];
        }
        if ($post['images']) {
            $images_class = (count($post['images']) > 1) ? 'multiple' : 'single';
            $images = '<div class="tweetledummer-post__images tweetledummer-post__images--' . $images_class . '">';
            foreach ($post['images'] as $image) {
                $images .= "\n" . '<a href="' . $image['url'] . '"><img class="' . ($image['class'] ?? '') . '" alt="' . htmlentities($image['alt']) . '" src="' . $image['url'] . '"></a>' . "\n";
            }
            $images .= '</div>';
        }
        $embed = '';
        if ($post['embed']) {
            $image = '';
            if (!empty($post['embed']['thumb'])) {
                $image = '<img alt="' . htmlentities($post['embed']['description']) . '" src="' . $post['embed']['thumb'] . '">';
            }
            $host = parse_url($post['embed']['uri'], PHP_URL_HOST);
            if (!empty($post['embed']['description'])) {
                $description = $post['embed']['description'];
            }
            else {
                $description = $post['embed']['uri'];
                if (strlen($description) > self::LINK_MAX_LENGTH - 1) {
                    $description = substr($description, 0, self::LINK_MAX_LENGTH - 1) . self::ELLIPSIS;
                }
            }
            $embed = '<div class="tweetledummer-post__embed">'
                . '<a href="' . $post['embed']['uri'] . '">'
                . $image
                . '<div class="embed-text">'
                . '<div class="embed-source">' . htmlentities($host) . '</div>'
                . '<div class="embed-title">' . htmlentities($post['embed']['title']) . '</div>'
                . '<div class="embed-description">' . htmlentities($description) . '</div>'
                . '</div>'
                . '</a>'
                . '</div>';
        }
        $quoted = '';
        if ($post['quoted']) {
            $quoted = $this->showPost($post['quoted'], 'quoted');
        }
        $reply_to = '';
        if ($post['reply_to']) {
            $reply_to = $this->showPost($post['reply_to'], 'reply-to');
        }
        $reposted_by = '';
        if ($post['repost']) {
            $reposted_by = '<div class="tweetledummer-post__reposted-by"><img width=20" height="20" src="images/repost.svg"> '
                . '<a href="' . $post['repost']['author_url'] . '">Reposted by ' . htmlentities($post['repost']['author_display_name']) . '</a>'
                . '</div>';
        }

        $link = '';
        if (!empty($post['embed']['uri'])) {
            $link_icon = 'link-solid.svg';
        }
        elseif (!empty($post['quoted'])) {
            $link_icon = 'quote-left-solid.svg';
        }
        elseif (!empty($post['reply_to'])) {
            $link_icon = 'reply-all-solid.svg';
        }
        else {
            $link_icon = 'link-solid.svg';
        }
        if (!empty($post['link_url_expanded'])) {
            $link = '<a href="' . $post['link_url_expanded'] . '"><img width=20" height="20" src="images/' . $link_icon .'"></a>';
        } elseif (!empty($post['link_url'])) {
            $link = '<a href="' . $post['link_url'] . '"><img width=20" height="20" src="images/' . $link_icon .'"></a>';
        }

        $age = $this->timeSince($post['timestamp'], 1);

        return <<<EOT
<div class="tweetledummer-post-wrapper {$class}">
    {$reposted_by}
    {$reply_to}
    <div class="tweetledummer-post">
        <div class="tweetledummer-post__left">
            <a class="tweetledummer-post__author-image" href="{$post['author_url']}"><img src="{$post['author_avatar']}"></a>
            <div></div>
        </div>
        <div class="tweetledummer-post__main">
            <div class="tweetledummer-post__header">
                <div class="tweetledummer-post__meta">
                    <a class="tweetledummer-post__author-image" href="{$post['author_url']}"><img src="{$post['author_avatar']}"></a>
                    <span class="tweetledummer-post__author-name"><a href="{$post['author_url']}">{$post['author_display_name']}</a></span>
                    <span class="tweetledummer-post__author-handle"><a href="{$post['author_url']}">@{$post['author_handle']}</a></span>
                    <span class="tweetledummer-post__age" title="{$post['created']}"> - {$age}</span>
                </div>
            </div>
            <div class="tweetledummer-post__body">
                {$post['text']}
                {$is_video}
                {$embed}
                {$quoted}
                {$images}
            </div>
            <div class="tweetledummer-post__footer">
                <div class="tweetledummer-post__stats">
                    {$post['like_count']}
                    {$post['repost_count']}
                    {$post['reply_count']}
                </div>
                <div class="tweetledummer-post__links">
                    {$link}
                    <a href="{$post['post_url']}"><img width=20" height="20" src="images/bsky.svg"></a>
                </div>
            </div>
        </div>
    </div>
</div>
EOT;
    }

    public function getEmbed($post, $class = '') {
        $embed = <<<EOT
<blockquote class="bluesky-embed %s" 
  data-bluesky-uri="%s"
  data-bluesky-cid="%s">
  <p lang="en">%s
  <br><br>
  <a href="%s?ref_src=embed">[image or embed]</a>
  </p>&mdash; %s
  (<a href="%s?ref_src=embed">@%s</a>)
  <a href="%s?ref_src=embed">%s</a>
  </blockquote>
EOT;
        return sprintf($embed,
            $class,
            $post['uri'],
            $post['cid'],
            htmlentities($post['text'] ?? ''),
            $post['post_url'],
            htmlentities($post['author_display_name'] ?? ''),
            $post['author_url'] ?? '',
            htmlentities($post['author_handle'] ?? ''),
            $post['post_url'],
            $post['created'] ?? '',
        );

    }

    public function getMaxPostId($unread = TRUE) {
        $sql = "SELECT MAX(id) AS max_id
          FROM tweetledummer_posts ";
        if ($unread) {
            $sql .= "WHERE `read` = 0 ";
        }
        $max_id = $this->db->query($sql)->fetch_object()->max_id;
        return $max_id;
    }

    private function timeSince($timestamp, $level = 6) {
        $date = new \DateTime();
        $date->setTimestamp($timestamp);
        $date = $date->diff(new \DateTime());
        // build array
//        $since = array_combine(['year', 'month', 'day', 'hour', 'minute', 'second'], explode(',', $date->format('%y,%m,%d,%h,%i,%s')));
        $since = array_combine(['y', 'mo.', 'd', 'h', 'm', 's'], explode(',', $date->format('%y,%m,%d,%h,%i,%s')));
        // remove empty date values
        $since = array_filter($since);
        // output only the first x date values
        $since = array_slice($since, 0, $level);
        // build string
        $last_key = key(array_slice($since, -1, 1, true));
        $string = '';
        foreach ($since as $key => $val) {
            // separator
            if ($string) {
                $string .= ($key != $last_key) ? ', ' : ' and ';
            }
            // set plural
//            $key .= $val > 1 ? 's' : '';
            // add date value
            $string .= $val . $key;
        }
        return $string;
    }

}
