<?php
$settings = [];
include 'settings.php';
include '../vendor/autoload.php';
include 'src/TweetledummerBluesky.php';

use Tweetledummer\TweetledummerBluesky;

$tweetledum = new TweetledummerBluesky($settings);
$profile = $tweetledum->getProfile();

//print '<pre><hr>$profile<hr>' . print_r($profile, true) . '<hr></pre>';

$profile_img = NULL;
if (!empty($profile->avatar)) {
    $user_url = 'https://bsky.app/profile/' . $profile->handle;
    $profile_img = '<div class="profile-image"><a target="_blank" href="' . $user_url . '">'
        . '<img width="30" height="30" src="' . $profile->avatar . '" />'
        . '</a></div>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Tweetledum Timeline</title>
    <link type="text/css" rel="stylesheet" href="css/styles.css" media="all" />
</head>
<body class="read">

<div class="info-bar">
    <div class="info-bar__inner">
        <div class="info-bar__items">
            <?php
            if (!empty($profile_img)) {
                print $profile_img;
            }
            ?>
            <div>
                <a href="/bulk.php"><img src="images/list.svg" width="30" height="30" alt="Bulk" title="Bulk"></a>
            </div>
        </div>
        <div class="info-bar__items">
            <div>
                <span id="current-view"></span>
            </div>
            <div>
                <span id="unread-count">0</span>
            </div>
        </div>
        <div class="tweetledum-controls" style="display: none;">
            <button class="tweetledum-controls-up" data-keycode="75">⬆️</button>
            <button class="tweetledum-controls-open" data-keycode="86">👓</button>
            <button class="tweetledum-controls-down" data-keycode="74">⬇️</button>
        </div>
    </div>
</div>
<div class="main">
    <div class="tweetledum-feed"></div>
    <button id="load-more">Load More Posts</button>
</div>

<script src="https://code.jquery.com/jquery-3.2.1.min.js" crossorigin="anonymous"></script>
<script src="js/jquery.visible.min.js"></script>
<script src="js/tweetledummer.js"></script>
<script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>

</body>
</html>
