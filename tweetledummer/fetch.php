<?php
$settings = [];
include 'settings.php';
include '../vendor/autoload.php';
include 'src/TweetledummerBluesky.php';
include 'src/TweetledummerController.php';

$controller = new TweetledummerController($settings);
$num = $controller->fetchPosts();
print $num;
