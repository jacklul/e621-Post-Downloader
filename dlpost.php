<?php

use GuzzleHttp\Client;

ini_set('memory_limit', '512M');

echo 'e621 downloader' . PHP_EOL . PHP_EOL;
echo 'Please enter either:
- Post URL / ID
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
    'LOGIN'            => '',
    'API_KEY'          => '',
];

if (file_exists(__DIR__ . '/config.cfg')) {
    echo 'Loading config: ' . __DIR__ . '/config.cfg' . PHP_EOL;

    $user_config = parse_ini_file(__DIR__ . '/config.cfg');
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

function downloadAndSave($url, $path_extra = '')
{
	global $failures;
	$file = basename($url);
	echo 'Downloading \'' . $file . '\'... ';

	if (!empty($path_extra)) {
        if (!mkdir($concurrentDirectory = getcwd() . '/' . trim($path_extra), 0755, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

		$path_extra = rtrim($path_extra, '/') . '/';
	}
	
	$md5 = explode('.', $file);
	$md5 = $md5[0];
	
	if (!file_exists(getcwd() . '/' . $path_extra . $file) || md5_file(getcwd() . '/' . $path_extra . $file) !== $md5) {
		$data = file_get_contents($url);
		
		if (file_put_contents(getcwd() . '/' . $path_extra . $file, $data)) {
			echo 'success!' . PHP_EOL;
		} else {
			
			echo 'failure!' . PHP_EOL;
			$failures++;
		}
	} else {
		echo 'exists!' . PHP_EOL;
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
    /** @noinspection NotOptimalIfConditionsInspection */
    if (preg_match("/.*(e621|e926)\.net.*\/(show|posts)\/(\d+).*/", $entry, $matches) || is_numeric($entry)) {
        /** @noinspection NotOptimalIfConditionsInspection */
        if (is_numeric($entry)) {
			$id = $entry;
		} else {
			$id = $matches[3];
		}
		
		echo 'Post download: ' . $id . PHP_EOL;

		$result = getPosts(['id' => $id]);
		if (is_array($result)) {
			$url = $result['posts'][0]['file']['url'];
			
			downloadAndSave($url);
		} else {
			echo 'API failure: ' . $result . PHP_EOL;
		}
	} elseif (preg_match("/(e621|e926)\.net.*([a-f0-9]{32}).*$/", $entry, $matches)) {
		echo 'Direct download: ' . $entry . PHP_EOL;
		downloadAndSave($entry);
	} elseif(!empty($entry)) {
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
		
		while(true) {
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
					downloadAndSave($post['file']['url'], $tags_unique_hash);
					$file_count++;
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
		
		if ($failures > 0 ) {
			echo 'Failures: ' . $failures . PHP_EOL;
		}
	}
}
