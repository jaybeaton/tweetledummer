<?php
$settings = [];
include 'settings.php';
include '../vendor/autoload.php';
include 'src/TweetledummerBluesky.php';
include 'src/TweetledummerController.php';

$controller = new TweetledummerController($settings);
$info = $controller->tweetledummer->refreshAuthorInfo( 'bluesky_author_info:' . $controller->tweetledummer->blueskyUsername);
print count($info ?? []);
