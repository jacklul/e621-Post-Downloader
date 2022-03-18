<?php

define('DS', DIRECTORY_SEPARATOR);

use GuzzleHttp\Client;

ini_set('memory_limit', '512M');

echo 'e621 downloader' . PHP_EOL . PHP_EOL;
echo 'Please enter either:
- Post URL / ID / MD5
- Link to image
- Set of tags' . PHP_EOL . PHP_EOL;

if (!isset($argv[1])) {
	$argv[1] = readline("> ");
	echo PHP_EOL;
}

$argv[1] = trim($argv[1]);

if (is_file($argv[1])) {
	$entries = file($argv[1]);
} else {
	$entries = [$argv[1]];
}

require __DIR__ . '/vendor/autoload.php';

$config = [
	'LOGIN'   => '',
	'API_KEY' => '',
];

$cdir = __DIR__;
if (substr(__DIR__, 0, 4) === 'phar') {
	$cdir = dirname(str_replace('phar://', '', __DIR__));
}

if (file_exists($cdir . DS . 'config.cfg')) {
	echo 'Loading config: ' . $cdir . DS . 'config.cfg' . PHP_EOL;

	$user_config = parse_ini_file($cdir . DS . 'config.cfg');
	$config = array_merge($config, $user_config);
}

$options = [
	'base_uri' => 'https://e621.net',
	'headers' => [
		'User-Agent' => 'e621 Post Downloader - @jacklul on Telegram',
	],
	'timeout' => 60,
];

if (!empty($config['LOGIN']) && !empty($config['API_KEY'])) {
	$options['auth'] = [$config['LOGIN'], $config['API_KEY']];
}

$client = new Client($options);

function createFileName(array $post): string
{
	$invalid_artists = ['unknown_artist_signature', 'unknown_colorist', 'anonymous_artist', 'avoid_posting', 'conditional_dnp', 'sound_warning', 'epilepsy_warning'];

	$artists = $post['tags']['artist'];
	$artists = array_diff($artists, $invalid_artists);
	$artists = array_values($artists);

	if (empty($artists)) {
		$artists[] = 'unknown_artist';
	}

	$maxTagsPerCat = [
		10, // artist
		15, // character
		25, // species
	];
	do {
		$tags = '';

		foreach ([
			$artists,
			$post['tags']['character'],
			$post['tags']['species'],
		] as $index => $tags_set) {
			$tags_set = array_splice($tags_set, 0, $maxTagsPerCat[$index]);

			if (!empty($tags_set)) {
				$tags .= '-' . implode('-', $tags_set);
			}
		}

		$tags = str_replace(['"', ':'], '', $tags);
		$filename = $post['id'] . $tags;

		if ($maxTagsPerCat[2] > 0) {
			$maxTagsPerCat[2]--;
		} elseif ($maxTagsPerCat[1] > 1) {
			$maxTagsPerCat[1]--;
		} elseif ($maxTagsPerCat[0] > 1) {
			$maxTagsPerCat[0]--;
		} else {
			return substr($filename, 0, 128);
		}
	} while (strlen($filename) > 128);

	return $filename;
}

function downloadAndSave($post, $path_extra = '')
{
	global $failures;

	$url = $post['file']['url'];
	$file = basename($url);

	echo 'Downloading \'' . $file . '\'... ';

	if (!empty($path_extra)) {
		@mkdir(getcwd() . '/' . trim($path_extra), 0755, true);
		$path_extra = rtrim($path_extra, '/') . '/';
	}

	$file = createFileName($post) . '.' . $post['file']['ext'];
	$md5 = $post['file']['md5'];

	if (!file_exists(getcwd() . '/' . $path_extra . $file) || md5_file(getcwd() . '/' . $path_extra . $file) !== $md5) {
		$data = file_get_contents($url);

		if (file_put_contents(getcwd() . '/' . $path_extra . $file, $data)) {
			echo 'success! (' . $file . ')' . PHP_EOL;
		} else {

			echo 'failure!' . PHP_EOL;
			$failures++;
		}
	} else {
		echo 'exists! (' . $file . ')' . PHP_EOL;
	}
}

function getPosts($data = [])
{
	global $client;

	if (isset($data['tags'])) {
		$tags = trim(preg_replace('!\s+!', ' ', $data['tags']));

		if (substr_count($tags, ' ') + 1 > 6) {
			return 'You can only search up to 6 tags.';
		}
	}

	try {
		$response = $client->request('GET', 'posts.json', ['query' => $data]);
		$result = json_decode((string)$response->getBody(), true);

		if (!is_array($result)) {
			return 'Data received from e621.net API is invalid';
		}

		return $result;
	} catch (Exception $e) {
		return $e->getMessage();
	}
}

$failures = 0;
foreach ($entries as $entry) {
	if (preg_match("/.*(e621|e926)\.net.*\/(show|posts)\/(\d+).*/", $entry, $matches) || is_numeric(trim($entry))) {
		if (is_numeric(trim($entry))) {
			$id = trim($entry);
		} else {
			$id = $matches[3];
		}

		echo 'Post (id) download: ' . $id . PHP_EOL;

		$result = getPosts(['tags' => 'id:' . $id]);
		if (is_array($result)) {
			if (isset($result['posts'][0]['file']['url'])) {
				downloadAndSave($result['posts'][0]);
			} else {
				echo 'Post not found: ' . $id . PHP_EOL;
			}
		} else {
			echo 'API failure: ' . $result . PHP_EOL;
		}
	} elseif (preg_match("/(e621|e926)\.net.*([a-f0-9]{32}).*$/", $entry, $matches)) {
		$md5 = $matches[2];

		echo 'Post (md5) download: ' . $md5 . PHP_EOL;

		$result = getPosts(['tags' => 'md5:' . $md5]);
		if (is_array($result)) {
			if (isset($result['posts'][0]['file']['url'])) {
				downloadAndSave($result['posts'][0]);
			} else {
				echo 'Post not found: ' . $md5 . PHP_EOL;
			}
		} else {
			echo 'API failure: ' . $result . PHP_EOL;
		}
	} elseif (preg_match('/^[a-f0-9]{32}$/i', trim($entry), $matches)) {
		$md5 = $matches[0];

		echo 'Post (md5) download: ' . $md5 . PHP_EOL;

		$result = getPosts(['tags' => 'md5:' . $md5]);
		if (is_array($result)) {
			if (isset($result['posts'][0]['file']['url'])) {
				downloadAndSave($result['posts'][0]);
			} else {
				echo 'Post not found: ' . $md5 . PHP_EOL;
			}
		} else {
			echo 'API failure: ' . $result . PHP_EOL;
		}
	} elseif (!empty($entry)) {
		echo 'Tags download: ' . $entry . PHP_EOL;
		$tags_unique_hash = preg_replace('/[^a-z0-9_]/i', ' ', strtolower($entry));

		$last_id = 0;
		$page = 1;
		$file_count = 0;
		$fail_count = 0;

		$use_page = false;
		if (strpos($entry, 'order:') !== false) {
			$use_page = true;
		}

		while (true) {
			if ($use_page) {
				echo 'Page: ' . $page . PHP_EOL;
				$result = getPosts(['tags' => $entry, 'page' => $page, 'limit' => 320]);
			} else {
				echo 'Before ID: ' . $last_id . PHP_EOL;
				$result = getPosts(['tags' => $entry, 'page' => 'b' . $last_id, 'limit' => 320]);
			}

			if (is_array($result)) {
				$posts = $result['posts'];

				if (empty($posts)) {
					break;
				}

				foreach ($posts as $post) {
					if (isset($post['file']['url'])) {
						downloadAndSave($post, $tags_unique_hash);
						$file_count++;
					} else {
						echo 'Post not found: '  . $post['id'] . PHP_EOL;
					}

					$last_id = $post['id'];
				}
			} else {
				echo 'API failure: ' . $result . PHP_EOL;
				$fail_count++;
				$failures++;
			}

			if ($fail_count > 10) {
				echo 'Multiple API failures, exiting...';
				break;
			}

			$page++;
		}

		echo PHP_EOL . 'Downloaded ' . $file_count . ' images!' . PHP_EOL;

		if ($failures > 0) {
			echo 'Failures: ' . $failures . PHP_EOL;
		}
	}
}
