<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// Config
$config = array();
$config['debug'] = true;

$config['delete'] = true;
$config['title'] = true;
$config['op'] = true;
$config['sage'] = true;
$config['noko'] = true;
$config['autonoko'] = false;
$config['capcodes'] = true;
$config['anonymous'] = false;
$config['anonymous_name'] = 'Anonymous';
$config['date_format'] = 'm/d/Y H:i:s'; // https://www.php.net/manual/en/datetime.format.php
$config['threads_per_page'] = 15;
$config['replies_per_post'] = 3;
$config['pages_in_nav'] = 10;
$config['sort_limits'] = array('15','50','100','150');

$config['boards'] = array("b", "a");

$config['db'] = array();
$config['db']['host'] = 'localhost';
$config['db']['user'] = 'root';
$config['db']['pass'] = '';
$config['db']['name'] = 'imageboard';
$config['db']['table'] = 'board_';

$config['sql'] = array();
$config['sql']['board'] = 'b';
$config['sql']['id'] = 'i';
$config['sql']['number'] = 'p';
$config['sql']['query'] = 'q';
$config['sql']['limit'] = 'l';
// Get Options
$sql = array();
$sql['board'] = isset($_GET[$config['sql']['board']]) ? $_GET[$config['sql']['board']] : false;
$sql['id'] = isset($_GET[$config['sql']['id']]) ? intval($_GET[$config['sql']['id']]) : 0;
$sql['number'] = isset($_GET[$config['sql']['number']]) ? intval($_GET[$config['sql']['number']]) : 1;
$sql['query'] = isset($_GET[$config['sql']['query']]) ? $_GET[$config['sql']['query']] : '';
$sql['limit'] = isset($_GET[$config['sql']['limit']]) ? intval($_GET[$config['sql']['limit']]) : $config['threads_per_page'];

$sql['limit'] = $sql['limit'] < 1 ? $config['threads_per_page'] : $sql['limit'];
// Initialize
if ($sql['board']) {
	if (!in_array($sql['board'], $config['boards'])) {
		header("Location: .");
	} else {
		$config['db']['table'] .= $sql['board'];

		$conn = mysqli_connect($config['db']['host'], $config['db']['user'], $config['db']['pass']);
		$conn->query("CREATE DATABASE IF NOT EXISTS " . $config['db']['name']);
		$conn->select_db($config['db']['name']);
		$conn->query("CREATE TABLE IF NOT EXISTS " . $config['db']['table'] . " (
			id INT(16) NOT NULL AUTO_INCREMENT,
			parent INT(16) DEFAULT NULL,
			timestamp INT(10) NOT NULL,
			lastbump INT(10) DEFAULT NULL,
			ip VARCHAR(15) NOT NULL,
			name VARCHAR(255) DEFAULT NULL,
			title LONGTEXT DEFAULT NULL,
			post LONGTEXT NOT NULL,
			op TINYINT(1) DEFAULT NULL,
			PRIMARY KEY (id)
		)");
	}
}
//
function plural($number) {
	return $number > 1 ? 's' : '';
}
function ago($time, $count = 2) {
    $time = time() - $time;
	if ($time < 1) { return 'Just now'; }
    $units = array(
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
	$humanized = array();
    foreach ($units as $unit => $text) {
        if ($time < $unit) continue;
        $subtime = floor($time / $unit);
		$time -= $unit * $subtime;
		array_push($humanized, $subtime . ' ' . $text . plural($subtime));
    }
	return implode(" ", array_slice($humanized, 0, $count)) . ' ago';
}
// DB Functions
function Post() {
	global $config, $sql, $conn;
	$time = time();
	$ip = $_SERVER['REMOTE_ADDR'];
	$name = !$config['anonymous'] ? $_POST["name"] : null;
	$title = $sql["id"] == 0 && $config['title'] ? $_POST["title"] : null;
	$post = $_POST["post"];
	$op = 0;
	if ($config['op'] && $_POST["op"] && $sql["id"] != 0) {
		$parent = Thread($sql["id"], true);
		if ($parent[0]['ip'] == $ip) {
			$op = 1;
		}
	}

	$stmt = $conn->prepare("INSERT INTO " . $config['db']['table'] . " (timestamp, lastbump, ip, parent, name, title, post, op) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iisisssi", $time, $time, $ip, $sql['id'], $name, $title, $post, $op);
    $stmt->execute();

	$header = 'Location: ?' . $config['sql']['board'] . '=' . $sql['board'];

	if ($sql['id'] != 0) {	
		if ($config['autonoko'] || ($config['noko'] && $_POST["noko"])) {
			$header .= '&' . $config['sql']['id'] . '=' . $sql['id'];
		}
		if (!$config['sage'] || !isset($_POST["sage"])) {	
				$stmt = $conn->prepare("UPDATE " . $config['db']['table'] . " SET lastbump = ? WHERE id = ?");
				$stmt->bind_param("is", $time, $sql['id']);
				$stmt->execute();
		}
	} else {
		$header .= $sql['limit'] == $config['threads_per_page'] ? '' : '&' . $config['sql']['limit'] . '=' . $sql['limit'];
	}

	header($header);
}

function Thread($id = false, $thread = false, $comments = false, $limit = false) {
	global $config, $sql, $conn;

	$query  = "SELECT * FROM " . $config['db']['table'] . " WHERE ";
	if ($thread) {
		$query  .= "id = " . $id;
	} else {
		$query  .= "parent = " . $id;
		if (!$comments) {
			$query  .= " ORDER BY lastbump DESC LIMIT " . $sql['limit'];
			$number = $sql['number'] - 1;
			if ($number > 0) {
				$query  .= " OFFSET " . $number * $sql['limit'];
			}
		} else if ($limit) {
			$query  .= " ORDER BY timestamp ASC LIMIT " . $config['replies_per_post'];
		}
	}
	$stmt = $conn->query($query);
	return $stmt->fetch_all(MYSQLI_ASSOC);
}

function DeletePost() {
	global $config, $sql, $conn;
	$thread = Thread($sql["id"], true);
	if ($thread[0]['ip'] == $_SERVER['REMOTE_ADDR']) {
		$stmt = $conn->query("DELETE FROM " . $config['db']['table'] . " WHERE id = " . $sql["id"] . " OR parent = " . $sql["id"]);	
	}
	$header = 'Location: ?' . $config['sql']['board'] . '=' . $sql['board'];
	$header .= $sql['limit'] == $config['threads_per_page'] ? '' : '&' . $config['sql']['number'] . '=' . $sql['number'] . '&' . $config['sql']['limit'] . '=' . $sql['limit'];
	header($header);
}

function CountPosts($id) {
	global $config, $conn;
	$stmt = $conn->query("SELECT COUNT(*) FROM " . $config['db']['table'] . " WHERE parent = " . $id);
	$result = $stmt->fetch_assoc();
	return reset($result);
}

// Draw Page Functions
function DrawHTML($body) {
	$html = <<<EOD
		<html>
			<head>
				<link rel="stylesheet" href="style3.css">
			</head>
			<body>
	EOD;
	$html .= $body;
	$html .= <<<EOD
			</body>
		</html>
	EOD;
	print $html;
}

function DrawPost($post, $main = false) { // todo
	global $config, $sql;
	$name = !$config['anonymous'] && $post['name'] ? $post['name'] : $config['anonymous_name'];
	$date = date($config['date_format'], $post['timestamp']);
	$date2 = ago($post['timestamp']);
	$parent = $post['parent'] != 0 ? $post['parent'] : $post['id'];
	$content = <<<EOD
		<div class="container" id="{$post['id']}">
			<div class="posthead">
	EOD;
	if ($config['delete'] && $post['ip'] == $_SERVER['REMOTE_ADDR']) {
		$limits = $sql['limit'] == $config['threads_per_page'] ? '' : '&' . $config['sql']['number'] . '=' . $sql['number'] . '&' . $config['sql']['limit'] . '=' . $sql['limit'];
		$content .= <<<EOD
				<div class="post_delete">
					<a href="?{$config['sql']['query']}=delete&{$config['sql']['board']}={$sql['board']}&{$config['sql']['id']}={$post['id']}{$limits}">
						X
					</a>
				</div>
		EOD;
	}
	if ($post['title']) {
		$content .= <<<EOD
				<div class="post_title">{$post['title']}</div>
		EOD;
	}
	$content .= <<<EOD
				<div class="poster_name">{$name}</div>
	EOD;
	if ($config['op'] && $post['op']) {
		$content .= <<<EOD
				<div class="post_op" title="OP">🟊</div>
		EOD;
	}
	$content .= <<<EOD
				<div class="post_date" title="{$date}">{$date2}</div>
				<div class="post_id">
					<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['id']}={$parent}#{$post['id']}">
						#{$post['id']}
					</a>
				</div>
			</div>
			<div class="postbody">
	EOD;
	if ($post['post']) {
		$content .= <<<EOD
				<div class="post_block">{$post['post']}</div>
		EOD;
	}
	if ($main) {
		$content .= <<<EOD
				<div class="post_nav">
		EOD;
		$replies = CountPosts($post['id']) - $config['replies_per_post'];
		if ($replies > 0) {
			$content .= <<<EOD
					<div class="replies_count">
						{$replies} replies omitted
					</div>
			EOD;
		}
		$content .= <<<EOD
					<div class="view_thread">
						<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['id']}={$parent}">
							View Thread
						</a>
					</div>
				</div>
		EOD;
	}
	$content .= <<<EOD
			</div>
		</div>
	EOD;
	return $content;
}

function DrawForm($ip = 0) {
	global $config, $sql;
	$limits = $sql['id'] == 0 && $sql['limit'] != $config['threads_per_page'] ? $limits = '&' . $config['sql']['limit'] . '=' . $sql['limit'] : '';
	$form = <<<EOD
		<form action="?{$config['sql']['board']}={$sql['board']}{$limits}&{$config['sql']['id']}={$sql['id']}&{$config['sql']['query']}=post" method="post">
	EOD;
	if (!$config['anonymous']) {
		$form .= <<<EOD
			<input type="text" name="name" placeholder="name"><br>
		EOD;
	}
	if ($sql['id'] == 0 && $config['title']) {
		$form .= <<<EOD
			<input type="text" name="title" placeholder="title"><br>
		EOD;
	}
	$form .= <<<EOD
		<input type="text" name="post" placeholder="comment..."><br>
	EOD;
	if ($sql['id'] == 0) {
	} else {
		if ($config['sage']) {
			$form .= <<<EOD
				<label for="sage">Sage:</label>
				<input type="checkbox" name="sage"><br>
			EOD;
		}
		if ($config['noko'] && !$config['autonoko']) {
			$form .= <<<EOD
				<label for="sage">Noko:</label>
				<input type="checkbox" name="noko"><br>
			EOD;
		}
		if ($config['op'] && $ip == $_SERVER['REMOTE_ADDR']) {
			$form .= <<<EOD
				<label for="op">OP:</label>
				<input type="checkbox" name="op"><br>
			EOD;
		}
	}
	$form .= <<<EOD
			<input type="submit">
		</form>
	EOD;
	return $form;	
}

function DrawThreadButtons($bottom = false) {
	global $config, $sql;
	if ($sql["id"] == 0) {
		$buttons = '';
	} else {
		$buttons = <<<EOD
				<div class="return_button">
					[<a href="?{$config['sql']['board']}={$sql['board']}">
						Return
					</a>]
				</div>
		EOD;
		$bottom = $bottom ? 'top' : 'bottom';
		$buttons .= <<<EOD
				<div class="pos_button">
					[<a href="#{$bottom}">
					{$bottom}
					</a>]
				</div>
		EOD;
		$buttons .= <<<EOD
				<div class="refresh_button">
					[<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['id']}={$sql['id']}">
					Refresh
					</a>]
				</div>
		EOD;
	}
	return $buttons;
}

function DrawPageButtons($posts) {
	global $config, $sql;
	$pages = ceil($posts / $sql['limit']);
	$limits = $sql['limit'] == $config['threads_per_page'] ? '' : '&' . $config['sql']['limit'] . '=' . $sql['limit'];
	if ($pages < 2) { return ''; }
	if ($sql['number'] > 1) {
		$back = $sql['number'] - 1;
		$buttons = <<<EOD
			<div class="first_page_buttom">
				[<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['number']}=1{$limits}">
					<<
				</a>]
			</div>
			<div class="back_page_buttom">
				[<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['number']}={$back}{$limits}">
					<
				</a>]
			</div>
		EOD;
	} else {
		$buttons = '';
	}
	$xyz = $sql['number'] - $config['pages_in_nav'];
	$xyz2 = $sql['number'] + $config['pages_in_nav'];
	$bigger = $pages < $xyz2 ? $pages : $xyz2;
	$chme = $xyz < 1 ? 1 : $xyz;
	for ($page = $chme; $page <= $bigger; $page++) {
		$buttons .= <<<EOD
			<div class="page_buttom">
		EOD;
		if ($page == $sql['number']) {
			$buttons .= <<<EOD
						[{$page}]
				EOD;
		} else {
			$buttons .= <<<EOD
					[<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['number']}={$page}{$limits}">
						{$page}
					</a>]
			EOD;
		}
		$buttons .= <<<EOD
			</div>
		EOD;
	}
	if ($sql['number'] < $pages) {
		$next = $sql['number'] + 1;
		$buttons .= <<<EOD
			<div class="next_page_buttom">
				[<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['number']}={$next}{$limits}">
					>
				</a>]
			</div>
			<div class="last_page_buttom">
				[<a href="?{$config['sql']['board']}={$sql['board']}&{$config['sql']['number']}={$pages}{$limits}">
					>>
				</a>]
			</div>
		EOD;
	}
	$buttons .= <<<EOD
			<div class="limit_page_buttom">
	EOD;
	$pagenum = $sql['number'] > 1 ? '&' . $config['sql']['number'] . '=' . $sql['number'] : '';
	foreach ($config['sort_limits'] as $limit) {
		$buttons .= <<<EOD
				<a href="?{$config['sql']['board']}={$sql['board']}{$pagenum}&{$config['sql']['limit']}={$limit}">{$limit}</a>
		EOD;
	}
	$buttons .= <<<EOD
			</div>
	EOD;
	return $buttons;
}
	
function DrawPosts() {
	global $config, $sql;
	$threads = Thread($sql["id"], $sql['id'] != 0);
	$count = CountPosts($sql["id"]);
	if ($count - (($sql['number'] - 1) * $sql['limit']) < 1 && $sql['number'] > 1) {
		$header = 'Location: ?' . $config['sql']['board'] . '=' . $sql['board'] . '&' . $config['sql']['number'] . '=' . ceil($count / $sql['limit']);
		$header .= $sql['limit'] == $config['threads_per_page'] ? '' : '&' . $config['sql']['limit'] . '=' . $sql['limit'];
		header($header);
		exit();
	}
	if ($sql["id"] == 0 || $threads && $threads[0]["parent"] == 0) {
		$posts = <<<EOD
			<header id="top">
				<h1>/{$sql['board']}/ - TEST</h1>
				<h3>Test deez nutz</h3>
				<div class="form">
		EOD;
		$posts .= DrawForm($threads ? $threads[0]['ip'] : false);
		$posts .= <<<EOD
				</div>
			</header>
			<main>
				<div class="nav">
		EOD;
		$posts .= DrawThreadButtons();
		$posts .= <<<EOD
				</div>
				<div class="threads">
		EOD;
		foreach ($threads as $thread) {
			$posts .= <<<EOD
					<div class="thread">
			EOD;
			$posts .= DrawPost($thread, $sql["id"] == 0);
			foreach (Thread($thread['id'], false, true, $sql['id'] == 0) as $post) {
				$posts .= <<<EOD
						<div class="post">
				EOD;
				$posts .= DrawPost($post);
				$posts .= <<<EOD
						</div>
				EOD;
			}
			$posts .= <<<EOD
					</div><br>
			EOD;
		}
		$posts .= <<<EOD
				</div>
				<div class="nav">
		EOD;
		if ($sql["id"] != 0) {
			$posts .= DrawThreadButtons(true);
		} else {
			$posts .= DrawPageButtons($count);
		}
		$posts .= <<<EOD
				</div>
			</main>
			<footer id="bottom">
				<p>Unnamed shit</p>
			</footer>
		EOD;
		DrawHTML($posts);
	}
}

function DrawIndex() {
	global $config;
	$body = <<<EOD
		<header>
			<h1>ImageBoard</h1>
		</header>
		<main>
	EOD;
	foreach ($config['boards'] as $board) {
		$body .= <<<EOD
			<a href="?{$config['sql']['board']}={$board}">
				/{$board}/
			</a><br>
		EOD;
	}
	$body .= <<<EOD
		</main>
	EOD;
	DrawHTML($body);
}

// Selector
switch ($sql['query']) {
	case 'post':
		Post();
		break;
	case 'delete':
		DeletePost();
		break;
	default:
		if ($sql['board']) {
			DrawPosts();
		} else {
			DrawIndex();
		}
		break;
}
?>