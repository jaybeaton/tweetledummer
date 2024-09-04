<?php

namespace Tweetledummer;

use cjrasmussen\BlueskyApi\BlueskyApi;

class TweetledummerBluesky {

    const DATE_FORMAT_DISPLAY = 'M j, Y \a\t h:i A';
    const DATE_FORMAT_DB = 'Y-m-d H:i:s';

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
            $this->blueskyRefreshToken = $query->get_result()->fetch_object()->value;
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

    public function getTimeline($args = []) {
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
            return FALSE;
        }

        $posts = [];
        foreach ($result->feed as $item) {
            $post = $item->post;
            $data = $this->getPostData($post);
            $data['full'] = $post;
            $posts[] = $data;
        } // Lop thru posts.

        $num_posts = count($posts);
        $cursor = $result->cursor;

        return $posts;

    }

    public function getPostData($post, $is_reply = FALSE) {
        //        $tz = date_default_timezone_get();
        $utc = new \DateTimeZone('UTC');
        $tz = new \DateTimeZone('America/New_York');

        $created_at = $post->record->createdAt ?? ($post->value->createdAt ?? NULL);
        $timestamp = ($created_at) ? strtotime($created_at) : FALSE;
        if ($timestamp !== FALSE) {
            $date = new \DateTime('@' . $timestamp, $utc);
            $date->setTimezone($tz);
            $created = $date->format(self::DATE_FORMAT_DISPLAY);
            //$created = $date->format(self::DATE_FORMAT_DB);
        } else {
            $created = '???';
        }
        $data = [
            'post_id' => $this->getPostIdFromUri($post->uri),
            'uri' => $post->uri,
            'cid' => $post->cid,
            'post_url' => $this->getPostUrl($post),
            'text' => $post->record->text ?? ($post->value->text ?? NULL),
            'author_did' => $post->author->did,
            'author_handle' => $post->author->handle,
            'author_display_name' => $post->author->displayName,
            'author_url' => $this->getAuthorUrl($post),
            'link_embed' => $post->record->embed->external->uri ?? NULL,
            'link_facet' => $post->record->facets[0]->features[0]->uri ?? NULL,
            'created' => $created,
            'timestamp' => $timestamp,
            'quoted' => [],
            'reply_to' => [],
        ];

        if (!$is_reply) {
            if (!empty($post->embed->record->cid)) {
                //$post->embed->record->value->{'$type'} = 'app.bsky.feed.post';
                $data['quoted'] = $this->getPostData($post->embed->record, TRUE);
            } elseif (!empty($post->record->reply->parent->cid)) {
                $data['reply_to'] = [
                    'post_id' => $this->getPostIdFromUri($post->record->reply->parent->uri),
                    'uri' => $post->record->reply->parent->uri,
                    'cid' => $post->record->reply->parent->cid,
                    'post_url' => $this->getPostUrlFromUri($post->record->reply->parent->uri),
                ];
            }
        }
        return $data;
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
        $url = 'https://bsky.app/profile/' . $post->author->did . '/post/' . $this->getPostIdFromUri($post->uri);
        return $url;
    }

    public function getAuthorUrl($post) {
        // https://bsky.app/profile/{$post['author']['did']}
        $url = 'https://bsky.app/profile/' . $post->author->did;
        return $url;
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

//        $embed = <<<EOT
//<blockquote class="bluesky-embed {$class}"
//  data-bluesky-uri="{$post['uri']}"
//  data-bluesky-cid="{$post['cid']}">
//  <p lang="en">{$post['text']}
//  <br><br>
//  <a href="{$post['post_url']}?ref_src=embed">[image or embed]</a>
//  </p>&mdash; {$post['author_display_name']}
//  (<a href="{$post['author_url']}?ref_src=embed">@{$post['author_handle']}</a>)
//  <a href="{$post['post_url']}}?ref_src=embed">{$post['created']}</a>
//</blockquote>
//<script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>
//EOT;
    }

    public function getMaxPostId() {
        $sql = "SELECT MAX(id) AS max_id
          FROM tweetledummer_posts
          WHERE `read` = 0 ";
        $max_id = $this->db->query($sql)->fetch_object()->max_id;
        return $max_id;
    }

}
