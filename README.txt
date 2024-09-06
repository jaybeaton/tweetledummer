Tweetledummer
===================

A simple PHP / Javascript web Bluesky timeline reader.

It stores a user's tweets in a MySQL database and lets the user view their
timeline in chronological order without missing past posts.

Based on my Tweetledum Twitter timeline reader:

https://github.com/jaybeaton/tweetledum

***

Only the contents of the tweetledummer/ directory are needed on the web server.

***

Create an app password in Bluesky here:

https://bsky.app/settings/app-passwords

Create your settings file by copying the default file here:

tweetledummer/default.settings.php

To this in the same directory:

tweetledummer/settings.php

Add your app password and Bluesky username to the settings file.

You must create a database table using the SQL found in db/tweetledummer.sql and
add your database credentials to the settings file mentioned above.

***

Tweetledummer uses

- cjrasmussen/BlueskyApi library:
https://github.com/cjrasmussen/BlueskyApi

- jquery-visible plugin:
https://github.com/customd/jquery-visible
