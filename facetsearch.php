<?php
// Proof-of-concept, try and get the facets of a deepcategory search
// in order to allow user to drill down.

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
	echo '<!doctype html><html><head><title>deepcat facet prototype</title></head><body>';
	echo '<h1>Commons deepcategory facet</h1>';
	echo '<p>This is a prototype of doing faceted search over commons category tree. This returns potential search facets 5 categories deep.</p>';
	echo '<p><form action="/facetsearch.php" method="GET"><label for="cat">Category:</lbel> <input id="cat" type="text" name="cat"><br><input type="submit" name="Search"></form>';
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
	$cat = mb_ucfirst( $cat, 'UTF-8' );
	if ( $cat !== $oldCat ) {
		$stmt->execute() or die( $stmt->error );
		$res = $stmt->get_result();
		if ( $res->num_rows !== 0 ) {
			return $cat;
		}
	}
	return false;
}

/**
 * Get page ids from list of categories.
 * @param $db DB handle
 * @param string $cats pipe separated list of categories
 * @return string SQL for use in where clause
 */
function getExcludedSQL( $db, $cats ) {
	if ( $cats === '' ) {
		return '';
	}

	$catList = explode( '|', $cats );
	$catList = array_map( function ( $cat ) use ( $db ) {
		$cat = trim( $cat );
		$cat = str_replace(' ', '_', $cat );
		$cat = mb_ucfirst( $cat, 'UTF-8' );
		return "'" . $db->real_escape_string( $cat ) . "'";
	}, $catList );
	$res = $db->query( 'SELECT page_id from page where page_namespace = 14 and page_title in (' . implode( ',', $catList ) . ');');
	if ( $res->num_rows < 1 ) {
		return '';
	}
	$excluded = [];
	foreach( $res as $row ) {
		$excluded[] = (int)$row['page_id'];
	}
	return ' AND cl_from not IN (' . implode( ',', $excluded ) . ') '; 
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

	$excludedCats = getExcludedSQL( $db, $_GET['exclude'] ?? '' );
	$res = getResults( $db, $cat, $excludedCats );

	if ( $res->num_rows === 0 ) {
		header( 'HTTP/1.1 400 error' );
		echo 'Category does not exist';
		return;
	}

	$json = [];
	$i = 0;
	$url = 'https://commons.wikimedia.org/w/index.php?title=Special:MediaSearch&search=incategory:';
	foreach( $res as $row ) {
		$json[] = [
			'title' => $row['page_title'],
			'files' => $row['files'],
			'wikidata' => (bool)$row['wikidata']
		];
	}
	header( 'content-type: application/json' );
	echo json_encode( $json );
}


// It would probably be more efficient to use blazegraph, but oh well.
// https://www.mediawiki.org/wiki/Wikidata_Query_Service/Categories
// MariaDB optimizer is kind of terrible at optimizing these types of queries
function getResults( $db, $targetCat, $excludedCats ) {
	$db->query( 'SET max_statement_time = 200' );
	$stmt = $db->prepare( "with recursive cats as ( select page_id, 0 as 'n', 0 as 'parent' from page  where page_namespace=14 and page_title = ? union  select cl_from as 'page_id', cats.n+1 as 'n', p1.page_id as 'parent' from categorylinks inner join linktarget on lt_id = cl_target_id  inner join page p1 on lt_namespace = p1.page_namespace and lt_title = p1.page_title inner join cats on cats.page_id = p1.page_id  where cl_type = 'subcat' $excludedCats and n < 5 ), fourth as ( select sum(c1.cat_files)+c2.cat_files 'files', parent from cats inner join page p1 on p1.page_id = cats.page_id left join category c1 on c1.cat_title = page_title inner join page p2 on p2.page_id = cats.parent left join category c2 on c2.cat_title = p2.page_title where n = 5 group by parent  ), third as ( select sum(coalesce(fourth.files, c1.cat_files))+c2.cat_files 'files', cats.parent from  cats left join fourth on cats.page_id = fourth.parent and cats.n=4 inner join page p1 on p1.page_id = cats.page_id left join category c1 on c1.cat_title = page_title inner join page p2 on p2.page_id = cats.parent left join category c2 on c2.cat_title = p2.page_title where n= 4 group by cats.parent ), second as ( select sum(coalesce(third.files, c1.cat_files))+c2.cat_files 'files', cats.parent from  cats left join third on cats.page_id = third.parent and cats.n=3 inner join page p1 on p1.page_id = cats.page_id left join category c1 on c1.cat_title = page_title inner join page p2 on p2.page_id = cats.parent left join category c2 on c2.cat_title = p2.page_title where n= 3 group by cats.parent ), first as ( select sum(coalesce(second.files, c1.cat_files))+c2.cat_files 'files', cats.parent from  cats left join second on cats.page_id = second.parent and cats.n=2 inner join page p1 on p1.page_id = cats.page_id left join category c1 on c1.cat_title = page_title inner join page p2 on p2.page_id = cats.parent left join category c2 on c2.cat_title = p2.page_title where n= 2 group by cats.parent ) select page_title, max(files) as 'files', max(cl_target_id) as 'wikidata' from  ((select  parent, max(files) 'files' from first group by 1 order by files desc limit 100 ) union all ( select parent,max(files) from second group by 1 order by files desc limit 300 ) union all ( select parent,max(files) from third group by 1 order by files desc limit 100 ) union all ( select  parent,max(files) from fourth group by 1 order by files desc limit 100 ) order by files desc) as res inner join page on page_id = res.parent left join categorylinks on res.parent = cl_from and cl_target_id = 9701451 group by res.parent order by 2 desc limit 100" );

	$stmt->bind_param( "s", $targetCat ) or die( "Could not bind" );
	$stmt->execute() or die( $stmt->error );
	return $stmt->get_result();
}

