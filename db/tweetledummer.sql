CREATE TABLE `tweetledummer_posts` (
  `id` varchar(32) COLLATE utf8mb4_bin DEFAULT NULL,
  `user` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `author` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `body` text COLLATE utf8mb4_bin,
  `data` text COLLATE utf8mb4_bin,
  `read` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `timestamp` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `read` (`read`),
  KEY `user` (`user`),
  KEY `author` (`author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `tweetledummer_lists` (
  `user` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `data` text COLLATE utf8_unicode_ci,
  `timestamp` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `tweetledummer_key_value` (
  `key` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `value` text COLLATE utf8mb4_bin,
  `timestamp` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
