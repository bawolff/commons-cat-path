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
if ( isset( $_GET['cat'] ) ) {

	doSearch();
} elseif ( isset( $_GET['source'] ) ) {
	highlight_file( __FILE__ );
} else {
	doWeb();
}

function doWeb() {
	echo '<!doctype html><html><head><title>Smart deepcat prototype</title></head><body>';
	echo '<h1>Commons smart deepcategory</h1>';
	echo '<p>This is a prototype of doing deepcategory but smarter to exclude some common category names that often give poor results. This is just a prototype to validate the idea. This will only do up to 100 subcategories, where the real deepcategory will do up to 5000.</p>';
	echo '<p><form action="/smartdeepcat.php" method="GET"><label for="cat">Category:</lbel> <input id="cat" type="text" name="cat"><br><input type="submit" name="Search"></form>';
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

function doSearch() {
	$db = getDB();
	$cat = str_replace(' ', '_', $_GET['cat'] ?? '' );

	if ( substr( strtolower($cat), 0, 9 ) === 'category:' ) {
		$cat = substr($cat, 9);
	}

	$cat = getCategoryName( $db, $cat );

	if ( $cat === false ) {
		header( 'HTTP/1.1 400 error' );
		echo 'Category does not exist';
		return;
	}

	$depth = (int)( $_GET['depth'] ?? 5 ); // CirrsuSearch uses 5

	$res = getResults( $db, $cat, $depth );

	if ( $res->num_rows === 0 ) {
		header( 'HTTP/1.1 400 error' );
		echo 'Category does not exist';
		return;
	}

	$json = [];
	$i = 0;
	$url = 'https://commons.wikimedia.org/w/index.php?title=Special:MediaSearch&search=incategory:';
	foreach( $res as $row ) {
		$url = $url . 'id:' . $row['page_id'] . '%7c';
	}
	$url = substr( $url, 0, -3 );
	header( 'Location: '. $url );
	echo htmlspecialchars( $url );
}


// It would probably be more efficient to use blazegraph, but oh well.
// https://www.mediawiki.org/wiki/Wikidata_Query_Service/Categories
// MariaDB optimizer is kind of terrible at optimizing these types of queries
function getResults( $db, $targetCat, $depth ) {
	$db->query( 'SET max_statement_time = 100' );
	// incategory: is limited to 100 categories. Core's deepcategory does 5000.
	$stmt = $db->prepare( "with recursive cats as ( select page_id, 0 as 'n' from page where page_namespace=14 and page_title = ? union all select cl_from as 'page_id', cats.n+1 as 'n' from categorylinks inner join linktarget on lt_id = cl_target_id  inner join page on lt_namespace = page_namespace and lt_title = page_title inner join cats on cats.page_id =page.page_id where cl_type = 'subcat' and n < ? ) select page_id from cats group by page_id order by min(n), page_id limit 100" );

	$stmt->bind_param( "si", $targetCat, $depth ) or die( "Could not bind" );
	$stmt->execute() or die( $stmt->error );
	return $stmt->get_result();
}

