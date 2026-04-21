<?php

header( 'X-Accel-Buffering: no' );
$start = time();
function foo() {
	global $start;
	error_log( "foo at shutdown " . connection_status() . " time= " . (time()-$start) );
}
register_shutdown_function( 'foo' );

#ignore_user_abort( false );

for ( $i = 0; $i < 20; $i++) {
sleep(1);
echo "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\n";
flush();
if ( connection_aborted() ) {
	error_log( "aborted" );
} else {
error_log( "not aborted" );
}
}
echo "done";

