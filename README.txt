A simple PHP / Javascript web Twitter timeline reader based on the Tweetledee
library.

https://github.com/tweetledee/tweetledee

It stores a user's tweets in a MySQL database and lets the user view their
timeline in chronological order without missing past tweets.

***

Only the contents of the tweetledum/ directory are needed on the web server.

***

Create your key file by copying the default file here:

tweetledum/tldlib/keys/default.tweetledee_keys.php

To this in the same directory:

tweetledum/tldlib/keys/tweetledee_keys.php

You must create a database table using the SQL found in db/tweetledummer.sql and
add your database credentials to the key file mentioned above.


Uses jquery-visible plugin:

https://github.com/customd/jquery-visible
