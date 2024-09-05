<?php

use Tweetledummer\TweetledummerBluesky;

class TweetledummerController {

    const DATE_FORMAT_DISPLAY = 'M j, Y \a\t h:i A';

    public $tweetledummer;

    private $db;

    private $errors = [];

    private $messages = [];

    public function __construct($settings) {
        try {
            $this->tweetledummer = new TweetledummerBluesky($settings);
            $this->db = new \mysqli($settings['database']['host'],
                $settings['database']['user'],
                $settings['database']['password'],
                $settings['database']['database']);
            $this->db->set_charset($settings['database']['charset']);
            if ($this->db->connect_errno > 0) {
                die('Unable to connect to database [' . $this->db->connect_error . ']');
            }
            $this->messages = [];
            if (!empty($_SESSION['status_messages'])) {
                $this->messages = $_SESSION['status_messages'];
            }
            $_SESSION['status_messages'] = [];
        } catch (\Exception $e) {
            // TODO: Handle the exception however you want
        }
    }

    public function fetchPosts() {

        $max_post_id = $this->tweetledummer->getMaxPostId();

        $sql = "REPLACE INTO tweetledummer_posts
            (id, `user`, author, body, `data`, `timestamp`)
            VALUES
            (?, ?, ?, ?, ?, ?) ";
        $query = $this->db->prepare($sql);

        $num = 0;
        if (!$posts = $this->tweetledummer->getTimeline(['limit' => 100])) {
            return 0;
        }
        foreach ($posts as $post) {

            if ($max_post_id > $post['post_id']) {
                continue;
            }

            $link_url = '';
            $link_url_expanded = '';

            if (!empty($post['link_embed'])) {
                $link_url = $post['link_embed'];
                $link_url_expanded = $post['link_embed'];
            } elseif (!empty($post['link_facet'])) {
                $link_url = $post['link_facet'];
                $link_url_expanded = $post['link_facet'];
            } elseif (!empty($post['reply_to']['post_url'])) {
                $link_url = $post['post_url'];
                $link_url_expanded = $post['reply_to']['post_url'];
            }
            $quoted = [];

            $data_field = array(
                'author_display_name' => $post['author_display_name'],
                'author_handle' => $post['author_handle'],
                'author_url' => $post['author_url'],
                'uri' => $post['uri'],
                'cid' => $post['cid'],
                'post_url' => $post['post_url'],
                'link_url' => $link_url,
                'link_url_expanded' => $link_url_expanded,
                'quoted' => $quoted,
                'reply_to' => $post['reply_to'],
                'reposted' => $post['repost'],
            );
            $data_field = serialize($data_field);
            $author = (!empty($post['repost']['author_handle'])) ? $post['repost']['author_handle'] : $post['author_handle'];

            try {
                $query->bind_param('sssssi',
                    $post['post_id'],
                    $this->tweetledummer->blueskyUsername,
                    $author,
                    $post['text'],
                    $data_field,
                    $post['timestamp']);
                $query->execute();
            } catch (\Exception $e) {
                print $sql . '<br>' . $e->getCode() . ': ' . $e->getMessage();
                print '<pre><hr>$post<hr>' . print_r($post, true) . '<hr></pre>';
            }

            $num++;

        } // Loop thru posts.

        $query->close();

        return $num;

    }

    public function getPosts($id, $author = NULL, $list = NULL) {

        header('Access-Control-Allow-Origin: *');

        $num_per_page = 10;
        $max_display_link_len = 60;

        $sql = "SELECT id, `author`, body, `data`, `timestamp`
          FROM tweetledummer_posts
          WHERE id > ?
          AND `read` = 0 ";
        if ($author) {
            $sql .= "AND author = ? ";
        }
        if ($list) {
            // Get the authors in list.
            $authors = [];
            $sql2 = "SELECT data
                FROM tweetledummer_lists
                WHERE name = ? ";
            // @todo - We need to actually filter on the tweetledum user!
            // AND user = ? ";
            $query2 = $this->db->prepare($sql2);
            $query2->bind_param('s', $list);
            $query2->execute();
            if ($list_data = $query2->get_result()->fetch_object()->data) {
                $list_data = unserialize($list_data);
                if (is_array($list_data)) {
                    $authors = $list_data;
                }
            }
            $placeholders = str_repeat('?,', count($authors) - 1) . '?';
            $sql .= "AND author IN ({$placeholders}) ";
        }

        $sql .= " ORDER BY id ASC
            LIMIT {$num_per_page} ";
        $query = $this->db->prepare($sql);

        if ($list) {
            $types = str_repeat('s', count($authors));
            $vars = $authors;
            array_unshift($vars, $id);
            $query->bind_param('s' . $types, ...$vars);
        } elseif ($author) {
            $query->bind_param('ss', $id, $author);
        } else {
            $query->bind_param('s', $id);
        }
        $query->execute();
        $result = $query->get_result();

        $first_class = 'first';
        $utc = new \DateTimeZone('UTC');
        $tz = new \DateTimeZone('America/New_York');

        while ($row = $result->fetch_assoc()) {

            $row['data'] = unserialize($row['data']);
            $date = new \DateTime('@' . $row['timestamp'], $utc);
            $date->setTimezone($tz);
            $row['data']['created'] = $date->format(self::DATE_FORMAT_DISPLAY);

            $embed = $this->tweetledummer->getEmbed($row['data'])
             . $this->getExtraInfoMarkup($row['data'], $row['body']);


            $quoted = '';
            if (!empty($row['data']['quoted'])) {
                $quoted = $this->tweetledummer->getEmbed($row['data']['quoted'], 'quoted');
            }

            $reply_to = '';
            if (!empty($row['data']['reply_to'])) {
                $reply_to = $this->tweetledummer->getEmbed($row['data']['reply_to'], 'reply-to')
                  . $this->getExtraInfoMarkup($row['data']['reply_to'], $row['data']['reply_to']['text']);
            }

            $link_url = NULL;
            $link = NULL;
            if (!empty($row['data']['link_url_expanded'])) {
                $link_url = $row['data']['link_url_expanded'];
            } elseif (!empty($row['data']['link_url'])) {
                $link_url = $row['data']['link_url'];
            }

            if ($link_url && (!preg_match('~^https?://bsky.app/~', $link_url) || !empty($row['data']['retweeted']))) {
                $display_url = preg_replace('~^https?://~', '', $link_url);
                if (strlen($display_url) > $max_display_link_len) {
                    $display_url = htmlentities(substr($display_url, 0, ($max_display_link_len - 1))) . '&hellip;';
                } else {
                    $display_url = htmlentities($display_url);
                }
                $link = '<a href="' . $link_url . '">' . $display_url . '</a>';
            } else {
                $link_url = $row['data']['post_url'];
            }

            if ($link) {
                $embed = '<div class="link-url">'
                    . $link
                    . '</div>'
                    . $embed;
            }

            if (!empty($row['data']['reposted'])) {
                $embed = '<div class="retweet">'
                    . 'Reposted by <a href="' . $row['data']['reposted']['author_url'] . '">' . htmlentities($row['data']['reposted']['author_display_name'] . ' (@' . $row['data']['reposted']['author_handle'] . ')') . '</a>'
                    . '</div>'
                    . $embed;
            }

            if (!empty($quoted)) {
                $embed .= '<details class="tweetledum-quoted"><summary>Quoted</summary>'
                    . $quoted
                    . '</details>';
            }

            if (!empty($reply_to)) {
                $embed = '<div class="tweetledum-reply-to">Reply<div class="tweetledum-reply-to-embed">'
                    . $reply_to
                    . '</div></div>'
                    . $embed;
            }

            print <<<EOT
<div class="tweetledum-tweet tweetledum-new {$first_class}" id="tweetledum-{$row['id']}" data-id="{$row['id']}" data-url="{$link_url}" data-tweet="{$row['data']['post_url']}">
{$embed}
</div>


EOT;

            $first_class = '';

        } // Loop thru items.

        $query->close();

    }

    public function getExtraInfoMarkup($author, $body) {
        foreach (['author_display_name', 'author_handle'] as $key) {
            $author[$key] = htmlentities($author[$key]);
        }
        $post_author_link= '<a href="' . $author['author_url'] . '">' . $author['author_display_name'] . ' (@' . $author['author_handle'] . ')</a>';
        $post_body = htmlentities($body);
        return <<<EOT
    <div class="extra-info">
        <div class="extra-info-body">
            <div class="extra-info-author-info">
                <img width="40" height="40" src="images/circle-user-regular.svg">
                <div class="extra-info-author">
                    <div class="extra-info-author-name">{$author['author_display_name']}</div>
                    <div class="extra-info-author-handle">@{$author['author_handle']}</div>
                </div>
            </div>
            {$post_body}
        </div>
        <div class="extra-info-author-link">- {$post_author_link}</div>
    </div>
EOT;
    }

    public function markRead($id, $author = NULL, $list = NULL) {

        header('Access-Control-Allow-Origin: *');

        $success = 0;

        if ($id) {
            $sql = "UPDATE tweetledummer_posts
              SET `read` = 1
              WHERE `read` = 0
              AND id = ? ";
            $query = $this->db->prepare($sql);
            $query->bind_param('s', $id);
            $query->execute();
            $query->close();
            $success = 1;
        }

        $sql = "SELECT COUNT(id) as num_unread
          FROM tweetledummer_posts
          WHERE `read` = 0 ";
        if ($author) {
            $sql .= "AND author = '" . $this->db->real_escape_string($author) . "' ";
        }
        if ($list) {
            // Get the authors in list.
            $authors = [];
            $sql2 = "SELECT data
                FROM tweetledummer_lists
                WHERE name = ? ";
            // @todo - We need to actually filter on the tweetledum user!
            // AND user = ? ";
            $query2 = $this->db->prepare($sql2);
            $query2->bind_param('s', $list);
            $query2->execute();
            if ($list_data = $query2->get_result()->fetch_object()->data) {
                $list_data = unserialize($list_data);
                if (is_array($list_data)) {
                    foreach ($list_data as $author) {
                        $authors[] = "'" . $this->db->real_escape_string($author) . "'";
                    } // Loop thru authors.
                }
            }
            $sql .= "AND author IN (" . implode(',', $authors) . ") ";
        }

        $num_unread = $this->db->query($sql)->fetch_object()->num_unread;

        $this->db->close();

        $out = array(
            'success' => $success,
            'unread' => $num_unread,
        );

        print json_encode($out);

    }

    public function getLists() {
        $lists = [];
        // Get all lists.
        $sql = "SELECT name
          FROM tweetledummer_lists
          WHERE user = ?
          ORDER BY name ";
        $query = $this->db->prepare($sql);
        $query->bind_param('s', $this->tweetledummer->blueskyUsername);
        $query->execute();
        $result = $query->get_result();
        while ($row = $result->fetch_assoc()) {
            $lists[] = $row['name'];
        } // Loop thru lists.
        return $lists;
    }

    public function getCounts() {

        // Get all authors first.
        $sql = "SELECT DISTINCT t.author, 0 AS num_tweets
          FROM tweetledummer_posts t
          ORDER BY t.author ";
        $result = $this->db->query($sql);
        $all_authors = [];
        while ($row = $result->fetch_assoc()) {
            $all_authors[$row['author']] = $row;
        }

        // Then, get all with tweets.
        $sql = "SELECT t.author, COUNT(t.author) AS num_tweets
          FROM tweetledummer_posts t
          WHERE t.`read` = 0
          GROUP BY t.author
          ORDER BY num_tweets DESC, t.author ";
        $result = $this->db->query($sql);

        $counts = [];
        while ($row = $result->fetch_assoc()) {
            $counts[$row['author']] = $row;
            // Remove from "all authors" list.
            unset($all_authors[$row['author']]);
        }
        // Add authors without tweets to end.
        $counts += $all_authors;

        return $counts;

    }

    public function getUnread() {
        $sql = "SELECT COUNT(id) as num_unread
          FROM tweetledummer_posts
          WHERE `read` = 0 ";
        return $this->db->query($sql)->fetch_object()->num_unread;
    }

    private function getMessages($messages, $class) {
        if (!$messages) {
            return '';
        }
        $out = '<div class="messages ' . $class . '"><ul>';
        foreach ($messages as $message) {
            $out .= '<li>' . htmlentities($message) . '</li>';
        }
        $out .= '</ul>'
            . "</div>\n";
        return $out;
    }

    public function getErrors() {
        return $this->getMessages($this->errors, 'errors');
    }

    public function getStatusMessages() {
        return $this->getMessages($this->messages, 'status');
    }

    public function getListMembers($list_name) {
        $authors = NULL;
        $sql = "SELECT data
          FROM tweetledummer_lists
          WHERE user = ?
          AND name = ? ";
        $query = $this->db->prepare($sql);
        $query->bind_param('ss', $this->tweetledummer->blueskyUsername, $list_name);
        $query->execute();
        $result = $query->get_result()->fetch_object();
        if ($result && $list_data = $result->data) {
            $list_data = unserialize($list_data);
            if (is_array($list_data)) {
                $authors = $list_data;
                $this->messages[] = 'List "' . htmlentities($list_name) . '" loaded.';
            }
        }
        if (!$authors) {
            $this->errors[] = 'List "' . htmlentities($list_name) . '" could not be retrieved.';
        }
        return $authors;
    }

    public function saveList($list_name, $authors) {
        $list_data = serialize($authors);
        $timestamp = time();
        $sql = "REPLACE INTO tweetledummer_lists
            (user, name, data, timestamp)
            VALUES
            (?, ?, ?, ?) ";
        $query = $this->db->prepare($sql);
        $query->bind_param('sssi', $this->tweetledummer->blueskyUsername, $list_name, $list_data, $timestamp);
        $done = $query->execute();
        if ($done) {
            $_SESSION['status_messages'][] = 'List saved.';
            $current_url = $_SERVER['SCRIPT_NAME'] . '?list=' . urlencode($list_name);
            // Redirect back, if needed, to avoid POST resubmission messages.
            header('Location: ' . $current_url);
            die();
        }
        else {
            $this->errors[] = 'List not saved.';
        }

    }

    public function bulkMarkRead($authors) {

        $placeholders = implode(',', array_fill(0, count($authors), '?'));
        $sql = "UPDATE tweetledummer_posts
          SET `read` = 1
          WHERE `read` = 0
          AND author IN ({$placeholders}) ";
        $query = $this->db->prepare($sql);
        $query->bind_param(str_repeat('s', count($authors)), ...$authors);
        $query->execute();
        if ($query->affected_rows == 1) {
            $message = '1 tweet ';
        }
        else {
            $message = "{$query->affected_rows} tweets ";
        }
        if (count($authors) == 1) {
            $message .= 'from 1 author marked read.';
        }
        else {
            $message .= 'from ' . count($authors)  . ' authors marked read.';
        }
        $_SESSION['status_messages'][] = $message;
        // Redirect back, if needed, to avoid POST resubmission messages.
        header('Location: ' . $_SERVER['SCRIPT_NAME']);
        die();

        // @todo - Add an "undo" based on an 'updated' timestamp.

    }

}