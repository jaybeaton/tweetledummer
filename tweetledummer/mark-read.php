<?php
$settings = [];
include 'settings.php';
include '../vendor/autoload.php';
include 'src/TweetledummerBluesky.php';
include 'src/TweetledummerController.php';

$controller = new TweetledummerController($settings);

$id = $_GET['id'] ?? '';
$author = $_GET['author'] ?? '';
$list = $_GET['list'] ?? '';

$controller->markRead($id, $author, $list);
