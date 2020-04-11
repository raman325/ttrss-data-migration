<?php
class Data_Migration extends Plugin {

	private $DATA_FORMAT_VERSION = 1;

	function init($host) {
		$host->add_command("data-user", "set username for import/export", $this, ":", "USER");
		$host->add_command("data-only-marked", "only export starred (or archived) articles", $this, "", "");
		$host->add_command("data-import", "import articles", $this, ":", "FILE.zip");
		$host->add_command("data-export", "export articles", $this, ":", "FILE.zip");
	}

	function about() {
		return array(1.0,
			"Migrates user articles using neutral format",
			"fox",
			true,
			"https://git.tt-rss.org/fox/ttrss-data-migration/wiki");
	}

	function data_only_marked($args) {
		//
	}

	function data_user($args) {
		//
	}

	function data_import($args) {
		$user = $args["data_user"];
		$input_file = $args["data_import"];

		if (!$user) {
			Debug::log("error: please set username using --data_user");
			exit(1);
		}

		$sth = $this->pdo->prepare("SELECT id FROM ttrss_users WHERE login = ?");
		$sth->execute([$user]);

		if ($row = $sth->fetch()) {
			$owner_uid = $row['id'];

			Debug::log("importing articles of user $user from $input_file...");

			$zip = new ZipArchive();

			if ($zip->open($input_file) !== TRUE) {
				Debug::log("unable to open $input_file");
				exit(3);
			}

			$total_imported = 0;
			$total_processed = 0;
			$total_feeds_created = 0;

			for ($i = 0; $i < $zip->numFiles; $i++) {
				Debug::log("processing " . $zip->getNameIndex($i));

				$batch = json_decode($zip->getFromIndex($i), true);

				if ($batch) {
					if ($batch["version"] == $this->DATA_FORMAT_VERSION) {
						if ($batch["schema-version"] == SCHEMA_VERSION) {
							$total_processed += count($batch["articles"]);

							list ($batch_imported, $batch_feeds_created) = $this->import_article_batch($owner_uid, $batch["articles"]);

							$total_imported += $batch_imported;
							$total_feeds_created += $batch_feeds_created;

						} else {
							Debug::log("batch has incorrect schema format version (expected: " .
								SCHEMA_VERSION . ", got: " . $batch["schema-version"]);
						}
					} else {
						Debug::log("batch has incorrect data format version (expected: " .
							$this->DATA_FORMAT_VERSION . ", got: " . $batch["version"]);
					}


				} else {
					Debug::log("error while decoding JSON data.");
				}
			}

			$zip->close();

			Debug::log("imported $total_imported (out of $total_processed) articles, created $total_feeds_created feeds.");

		} else {
			Debug::log("error: could not find user $user.");
			exit(4);
		}
	}

	function data_export($args) {
		$user = $args["data_user"];
		$output_file = $args["data_export"];
		$only_marked = isset($args["data_only_marked"]);

		if (!$user) {
			Debug::log("error: please set username using --data_user");
			exit(1);
		}

		$sth = $this->pdo->prepare("SELECT id FROM ttrss_users WHERE login = ?");
		$sth->execute([$user]);

		if ($row = $sth->fetch()) {
			$owner_uid = $row['id'];

			if (stripos($output_file, ".zip") === FALSE)
				$output_file = $output_file . ".zip";

			Debug::log("exporting articles of user $user to $output_file...");

			if ($only_marked)
				Debug::log("limiting export to marked and archived articles.");

			if (file_exists($output_file)) {
				Debug::log("refusing to overwrite existing output file.");
				exit(2);
			}

			$zip = new ZipArchive();

			if ($zip->open($output_file, ZipArchive::CREATE) !== TRUE) {
				Debug::log("unable to create $output_file");
				exit(3);
			}

			$offset = 0;
			$batch_size = 1000;
			$batch_seq = 0;
			$total_processed = 0;

			while (true) {
				$batch_filename = sprintf("%08d.json", $batch_seq);

				$batch = [
					"version" => $this->DATA_FORMAT_VERSION,
					"schema-version" => SCHEMA_VERSION,
					"articles" => $this->get_export_batch($owner_uid, $offset, $batch_size, $only_marked)
				];

				$offset += count($batch["articles"]);
				$total_processed += count($batch["articles"]);
				++$batch_seq;

				$zip->addFromString($batch_filename, json_encode($batch, false));

				if (count($batch["articles"]) != $batch_size)
					break;
			}

			if ($zip->close() !== TRUE) {
				Debug::log("write error while saving data to $output_file");
				exit(3);
			}

			Debug::log("exported $total_processed articles to $output_file.");

		} else {
			Debug::log("error: could not find user $user.");
			exit(4);
		}
	}
	
	private function get_export_batch($owner_uid, $offset, $batch_size, $only_marked)	{
		$rv = [];

		Debug::log("processing articles, offset: $offset");

		if ($only_marked)
			$export_filter_qpart = "(marked = true OR feed_id IS NULL) AND";
		else
			$export_filter_qpart = "";

		$sth = $this->pdo->prepare("SELECT
					ttrss_entries.guid,
					ttrss_entries.title,
					content,
					marked,
					published,
					score,
					note,
					link,
					tag_cache,
					label_cache,
					author,
					unread,
					ttrss_feeds.title AS feed_title,
					ttrss_feeds.feed_url AS feed_url,
					ttrss_entries.updated
				FROM
					ttrss_user_entries LEFT JOIN ttrss_feeds ON (ttrss_feeds.id = feed_id),
					ttrss_entries
				WHERE
				    $export_filter_qpart
					ref_id = ttrss_entries.id AND
					ttrss_user_entries.owner_uid = ?
				ORDER BY ttrss_entries.id LIMIT $batch_size OFFSET $offset");

		$sth->execute([$owner_uid]);

		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {

			foreach ($row as $k => $v) {
				if (is_bool($v)) {
					$row[$k] = (int)$v;
				}
			}

			array_push($rv, $row);
		}

		return $rv;
	}

	private function import_article_batch($owner_uid, $articles) {
		$total_imported = 0;
		$total_feeds_created = 0;

		foreach ($articles as $article) {

			$this->pdo->beginTransaction();

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_entries WHERE guid = ?");
			$sth->execute([$article['guid']]);

			$ref_id = false;

			if ($row = $sth->fetch()) {
				$ref_id = $row['id'];
			} else {
				$sth = $this->pdo->prepare(
					"INSERT INTO ttrss_entries
									(title, guid, link, updated, content, content_hash,
									no_orig_date,
									date_updated,
									date_entered,
									comments,
									num_comments,
									author)
								VALUES
									(:title, :guid, :link, :updated, :content, :content_hash,
									false, 
									 NOW(),
									NOW(),
									'',
									'0',
									:author)");

				$sth->execute([
					"title" => $article['title'],
					"guid" => $article['guid'],
					"link" => $article['link'],
					"updated" => $article['updated'],
					"content" => $article['content'],
					"content_hash" => sha1($article['content']),
					"author" => $article["author"]
				]);

				$sth = $this->pdo->prepare("SELECT id FROM ttrss_entries WHERE guid = ?");
				$sth->execute([$article['guid']]);

				if ($row = $sth->fetch()) {
					$ref_id = $row['id'];
				}
			}

			//print "Got ref ID: $ref_id\n";

			if ($ref_id) {

				$feed = NULL;

				if ($article['feed_url'] && $article['feed_title']) {

					$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds
									WHERE feed_url = ? AND owner_uid = ?");
					$sth->execute([$article['feed_url'], $owner_uid]);

					if ($row = $sth->fetch()) {
						$feed = $row['id'];
					} else {
						// try autocreating feed in Uncategorized...

						$sth = $this->pdo->prepare("INSERT INTO ttrss_feeds (owner_uid,
										feed_url, title) VALUES (?, ?, ?)");
						$res = $sth->execute([$owner_uid, $article['feed_url'], $article['feed_title']]);

						if ($res) {
							$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds
											WHERE feed_url = ? AND owner_uid = ?");
							$sth->execute([$article['feed_url'], $owner_uid]);

							if ($row = $sth->fetch()) {
								++$total_feeds_created;

								$feed = $row['id'];
							}
						}
					}
				}

				if ($feed)
					$feed_qpart = "feed_id = " . (int) $feed;
				else
					$feed_qpart = "feed_id IS NULL";

				//print "$ref_id / $feed / " . $article['title'] . "\n";

				$sth = $this->pdo->prepare("SELECT int_id FROM ttrss_user_entries
								WHERE ref_id = ? AND owner_uid = ? AND $feed_qpart");
				$sth->execute([$ref_id, $owner_uid]);

				if (!$sth->fetch()) {

					$score = (int) $article['score'];

					$tag_cache = $article['tag_cache'];
					$note = $article['note'];

					//print "Importing " . $article['title'] . "<br/>";

					++$total_imported;

					$sth = $this->pdo->prepare(
						"INSERT INTO ttrss_user_entries
									(ref_id, owner_uid, feed_id, unread, last_read, 
									 	marked, published, score, tag_cache, label_cache, uuid, note)
									VALUES (:ref_id, :owner_uid, :feed_id, :unread, NULL,
									    :marked, :published, :score, :tag_cache, '', '', :note)");

					$res = $sth->execute([
						"ref_id" => $ref_id,
						"owner_uid" => $owner_uid,
						"feed_id" => $feed,
						"marked" => (int)sql_bool_to_bool($article['marked']),
						"published" => (int)sql_bool_to_bool($article['published']),
						"unread" => (int)sql_bool_to_bool($article['unread']),
						"score" => $score,
						"tag_cache" => $tag_cache,
						"note" => $note]);

					if ($res) {

						if (DB_TYPE == "pgsql") {
							$ts_lang = get_pref('DEFAULT_SEARCH_LANGUAGE', $owner_uid);
							// TODO: maybe use per-feed setting if available?

							if (!$ts_lang)
								$ts_lang = 'simple';

							$sth = $this->pdo->prepare("UPDATE ttrss_entries
											SET tsvector_combined = to_tsvector(:ts_lang, :ts_content)
											WHERE id = :id");

							$sth->execute([
								"id" => $ref_id,
								"ts_lang" => $ts_lang,
								"ts_content" => mb_substr(strip_tags($article['title'] . " " . $article['content']), 0, 900000)
							]);
						}

						$label_cache = json_decode($article['label_cache'], true);

						if (is_array($label_cache) && $label_cache["no-labels"] != 1) {
							foreach ($label_cache as $label) {
								Labels::create($label[1],
									$label[2], $label[3], $owner_uid);

								Labels::add_article($ref_id, $label[1], $owner_uid);
							}
						}
					}
				}
			}

			$this->pdo->commit();
		}

		return [$total_imported, $total_feeds_created];
	}

	function api_version() {
		return 2;
	}

}
