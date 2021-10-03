<?php
/**
 * @file
 * Backward compatibility file to support require_once() in LocalSettings.
 *
 * Modern syntax (to enable GCS in LocalSettings.php) is
 * wfLoadExtension( 'GCS' );
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'GCS' );
} else {
	die( 'This version of the GCS extension requires MediaWiki 1.27+' );
}
