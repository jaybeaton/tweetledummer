<?php

session_start();

$settings = [];
include 'settings.php';
include '../vendor/autoload.php';
include 'src/TweetledummerBluesky.php';
include 'src/TweetledummerController.php';

$controller = new TweetledummerController($settings);
$profile = $controller->tweetledummer->getProfile();
$screen_name = NULL;
$profile_img = NULL;
if (!empty($profile->avatar)) {
    $screen_name = $profile->handle;
    $user_url = 'https://bsky.app/profile/' . $profile->handle;
    $profile_img = '<div class="profile-image"><a target="_blank" href="' . $user_url . '">'
        . '<img width="50" height="50" src="' . $profile->avatar . '" />'
        . '</a></div>';
}

$current_url = $_SERVER['SCRIPT_NAME'];

$errors = [];
$current_list = $_GET['list'] ?? NULL;
$lists = [];
$authors = $_POST['author'] ?? [];
$authors = array_filter($authors);
$mark_read = !empty($_POST['mark-read']);
$save_list = !empty($_POST['save-list']);
$list_name = $_POST['list-name'] ?? NULL;
$list = $_GET['list'] ?? NULL;

if ($mark_read) {

  if ($authors) {
      $controller->bulkMarkRead($authors);
  } // Got tweeters to mark.

}
elseif ($save_list) {
  if (!$list_name) {
    $errors[] = 'List name is required.';
  }
  else {
    $controller->saveList($list_name, $authors);
  }
}

if (!$list_name && $list) {
  $list_name = $list;
}

// Get the list to show.
if ($list_name) {
    $authors = $controller->getListMembers($list_name);
    if (is_null($authors)) {
        $list_name = NULL;
    }
}
$counts = $controller->getCounts();
$lists = $controller->getLists();
$num_unread = $controller->getUnread();
?>
<!doctype html>
<html lang="en">
<head>
  <!-- Required meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Tweetledum Bulk Mark-Read</title>
  <link type="text/css" rel="stylesheet" href="css/styles.css" media="all" />
</head>
<body class="bulk">

<div class="info-column">
  <?php
  if (!empty($profile_img)) {
    print $profile_img;
  }
  ?>
  <div>
      <a href="/"><img src="images/readme.svg" width="50" height="50" alt="Read" title="Read"></a>
  </div>
  <div>
    <div class="count-label">Unread</div> <span id="unread-count"><?php print $num_unread; ?></span>
  </div>
  <div>
    <div class="count-label">Selected</div> <span class="count-value" id="total-selected">0</span>
  </div>
  <div>
    <div class="count-label">Remaining</div> <span class="count-value" id="total-remaining">0</span>
  </div>

  <div class="lists-wrapper">
    <h3>Lists</h3>
    <div class="lists">
      <?php if ($lists) { ?>
        <ul>
          <?php foreach ($lists as $list) { ?>
            <li>
              <a href="bulk.php?list=<?php print urlencode($list); ?>"><?php print htmlentities($list); ?></a>
              [<a href="./#list:<?php print htmlentities($list) ?>">read</a>]
            </li>
          <?php } ?>
        </ul>
      <?php } else { ?>
        <div class="no-lists">
          You have no lists.
        </div>
      <?php } ?>
    </div>
  </div>

</div>
<div class="main">
  <div class="tweetledum-bulk">
    <?php
      print $controller->getErrors();
      print $controller->getStatusMessages();
    ?>
    <form id="bulk-mark-read" action="<?php print $current_url; ?>" method="post">
      <table class="bulk-mark-read">
        <tr>
          <th><input type="checkbox" id="bulk-toggle" /></th>
          <th>Author</th>
          <th>Count</th>
        </tr>
<?php
        foreach ($counts as $author => $row) {
          $checked = (in_array($author, $authors ?? [])) ? 'checked="checked"' : '';
          $id = 'tweeter__' . $author;
          print '<tr>';
          print '<td class="checkbox"><input type="checkbox" name="author[]" id="' . htmlentities($id) . '" value="' . htmlentities($author) . '" ' . $checked . ' data-count=' . $row['num_tweets'] . '" /></td>';
          print '<td class="tweeter"><label for="' . htmlentities($id) . '">' . htmlentities($author) . '</label></td>';
          print '<td class="count"><a href="./#' . htmlentities($author) . '">' . $row['num_tweets'] . '</a></td>';
          print "</tr>\n";
        }
?>
      </table>
      <div class="actions">
        <div>
          <input class="bulk-save bulk-save--mark" type="submit" name="mark-read" value="Mark read" />
        </div>
        <div class="list-fields">
          <div>
            <input class="bulk-input bulk-input--list" type="textfield" name="list-name" maxlength="255" value="<?php print htmlentities($list_name); ?>">
          </div>
          <div>
            <input class="bulk-save bulk-save--list" type="submit" name="save-list" value="Save list" />
          </div>
        </div>
      </div>
    </form>

  </div>
</div>

<script src="https://code.jquery.com/jquery-3.2.1.min.js" crossorigin="anonymous"></script>
<script src="js/tweetledummer-bulk-mark.js"></script>

</body>
</html>
