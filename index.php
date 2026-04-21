<?php

/*
MIT License

Copyright (c) 2026 Brian Wolff

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/


error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
header( 'Access-Control-Allow-Origin: *' );
header( 'Cache-Control: max-age: 1000, s-maxage=600, public' );
if ( isset( $_GET['file'] ) ) {

	doApi();
} elseif ( isset( $_GET['source'] ) ) {
	highlight_file( __FILE__ );
} else {
	doWeb();
}

function doWeb() {
	echo '<!doctype html><html><head><title>Commons Category path finder</title></head><body>';
	echo '<h1>Commons category path</h1>';
	echo '<p>This is a tool to get the path from a category to a specific file. It is mostly meant to be used as an API via the file, category, limit and depth parameter.</p>';
	echo '<p><form action="/" method="GET">Category. Leave blank to show all category paths: <input type="text" name="cat"><br>File (no prefix): <input type="text" name="file"><br>Limit: <input type="number" name="limit" value="1"><br><input type="submit" name="Lookup"></form>. API also supports depth and hidden parameters.';
	echo '<p><a href="?source=1">View source</a></p>';
}

function getDB() {
    $connection = new mysqli(
        'commonswiki.web.db.svc.wikimedia.cloud',
        getenv( 'TOOL_REPLICA_USER' ),
        getenv( 'TOOL_REPLICA_PASSWORD' ),
        'commonswiki_p'
    );

    if ($connection->connect_error) {
        throw new RuntimeException("Connection failed: " . $connection->connect_error);
    }

    $connection->set_charset( 'utf8mb4' );
    return $connection;
}

function getCategoryName( $db, $cat ) {
	$oldCat = $cat;	
	$stmt = $db->prepare( 'SELECT 1 from linktarget inner join categorylinks on cl_target_id = lt_id and cl_type = "subcat" where lt_namespace = 14 and lt_title = ? limit 1;' );
	$stmt->bind_param( "s", $cat ) or die( "Could not bind" );

	$stmt->execute() or die( $stmt->error );
	$res = $stmt->get_result();
	if ( $res->num_rows !== 0 ) {
		return $cat;
	}
	$cat = mb_convert_case( $cat, MB_CASE_TITLE, 'UTF-8' );
	if ( $cat !== $oldCat ) {
		$stmt->execute() or die( $stmt->error );
		$res = $stmt->get_result();
		if ( $res->num_rows !== 0 ) {
			return $cat;
		}
	}
	return false;
}

function doApi() {
	$db = getDB();
	register_shutdown_function( 'shutdown', $db ); 
	$file = str_replace(' ', '_', $_GET['file'] );
	$cat = str_replace(' ', '_', $_GET['cat'] ?? '' );
	$limit = (int)($_GET['limit'] ?? 1);
	if ( $limit < 1 ) $limit = 1;

	if ( substr( strtolower($cat), 0, 9 ) === 'category:' ) {
		$cat = substr($cat, 9);
	}
	if ( substr( strtolower($file), 0, 5 ) === 'file:' ) {
		$cat = substr($file, 5);
	}

	$cat = getCategoryName( $db, $cat );

	if ( $cat === false ) {
		header( 'HTTP/1.1 500 error' );
		header( 'Content-Type: text/json; charset=UTF-8' );
		echo json_encode( [ "error" => "Category does not exist"] );
		return;
	}

	if ( isset( $_GET['depth'] ) ) {
		$res = getResults( $db, $file, $cat, (int)($_GET['depth']), (bool)($_GET['hidden'] ?? false), $limit );
	} else {
		// Try going low depth first, then expand a bit
		// using hidden cats slow things down, so only try on low depth.
		$res = getResults( $db, $file, $cat, 5, false, $limit );
		if ( $res->num_rows === 0 ) $res = getResults( $db, $file, $cat, 5, true, $limit );
		if ( $res->num_rows === 0 ) $res = getResults( $db, $file, $cat, 9, false, $limit );
		if ( $res->num_rows === 0 ) $res = getResults( $db, $file, $cat, 13, false, $limit );
	}
	$json = [];
	$i = 0;
	foreach( $res as $row ) {
		$parts = explode( ' ', $row['path'] );
		foreach( $parts as $part ) {
			if ( $part === '' ) continue;
			$json[$i][] = $part;
		}
		$json[$i] = array_reverse( $json[$i] );
		$i++;
	}
header( 'Content-Type: text/json; charset=UTF-8' );
echo json_encode( $json, JSON_UNESCAPED_UNICODE );
}

// It would probably be more efficient to use blazegraph, but oh well.
// https://www.mediawiki.org/wiki/Wikidata_Query_Service/Categories
// MariaDB optimizer is kind of terrible at optimizing these types of queries
function getResults( $db, $image, $targetCat, $depth, $includeHidden, $limit ) {
	if ( !$includeHidden ) {
		$hidden = "left join page_props on page_id = pp_page and pp_propname = 'hiddencat'  where pp_propname is null and";
	} else {
		$hidden = 'WHERE';
	}
	$cat = '';
	if ( $targetCat !== '' ) {
		$cat = 'WHERE cats.cat = ?';
	}
	$db->query( 'SET max_statement_time = 300' );
	$stmt = $db->prepare( "with recursive cats as (select lt_title 'cat', CAST( concat( ' ', lt_title, ' ' ) AS char(4096) character set utf8mb4) as 'path', 1 as 'n' from categorylinks inner join page p1 on cl_from = p1.page_id inner join linktarget on lt_id = cl_target_id  where p1.page_namespace = 6 and page_title = ? UNION ALL select lt_title 'cat', right( concat( cats.path, ' ', lt_title, ' ' ), 4096) as 'path', cats.n+1 as 'n' from categorylinks inner join page p1 on cl_from = p1.page_id inner join linktarget on lt_id = cl_target_id inner join cats on page_title = cats.cat and page_namespace = 14 $hidden  n < ? and path not like concat( '% ', lt_title, ' %' )  ) select path from cats $cat order by n asc limit ?" );

	if ( $cat ) {
		$stmt->bind_param( "sisi", $image, $depth, $targetCat, $limit ) or die( "Could not bind" );
	} else {
		$stmt->bind_param( "sii", $image, $depth, $limit ) or die( "Could not bind" );
	}
	$stmt->execute() or die( $stmt->error );
	return $stmt->get_result();
}

// For large category trees this can be kind of slow
// Stop looking if the user has gone away.
// FIXME doesn't seem to work
function shutdown( $db ) {
error_log( "At shutdown - " . connection_status() );
	if ( connection_status() === 0 ) {
		return;
	}
	$threadId = (int)$db->thread_id;
error_log( "killng $threadId" );
	$res = $this->getDB()->kill( $threadId );
error_log( "done $res " );
}
