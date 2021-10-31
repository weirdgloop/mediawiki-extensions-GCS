<?php

/**
 * Implements the GCS extension for MediaWiki.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Hooks of Extension:GCS
 */
class GCSHooks {
	/**
	 * Call register() from $wgExtensionFunctions.
	 */
	public static function register() {
		global $wgFileBackends, $wgLocalFileRepo, $wgScriptPath, $wgThumbnailScriptPath;

		/* Needed zones */
		$privateZones = [
			'deleted',
			'temp',
		];
		$publicZones = [
			'public',
			'sitemaps',
			'thumb',
			'timeline',
			'transcoded',
		];
		$zones = [ ...$privateZones, ...$publicZones ];

		$wgLocalFileRepo = [
			'class' => LocalRepo::class,
			'name' => 'local',
			'backend' => 'local-gcs',
			'scriptDirUrl' => $wgScriptPath,
			'url' => $wgScriptPath . '/images',
			'hashLevels' => 0,
			'thumbScriptUrl' => $wgThumbnailScriptPath,
			'transformVia404' => true,
			'deletedHashLevels' => 0,
			'zones' => array_fill_keys( $zones, [ 'url' => false ] ),
		];

		// Not a private wiki: $publicZones must have an URL
		foreach ( $publicZones as $zone ) {
			$wgLocalFileRepo['zones'][$zone] = [
				'url' => '/images' . self::getRootForZone($zone)
			];
		}

		// Container names are prefixed by wfWikiID(), which depends on $wgDBPrefix and $wgDBname.
		$wikiId = wfWikiID();
		$containerPaths = [];
		foreach ( $zones as $zone ) {
			$containerPaths["$wikiId-local-$zone"] = $wikiId . self::getRootForZone($zone);
		}
		// GloopTweaks's "sitemaps" is unfortunately special.
		$containerPaths["$wikiId-sitemaps"] = $wikiId . self::getRootForZone('sitemaps');
		// EasyTimeline is unfortunately special.
		$containerPaths["$wikiId-timeline-render"] = $wikiId . self::getRootForZone('timeline');

		$wgFileBackends[] = [
			'class' => GCSFileBackend::class,
			'name' => 'local-gcs',
			'domainId' => $wikiId,
			'lockManager' => 'nullLockManager',
			'containerPaths' => $containerPaths,
		];
	}

	/**
	 * Returns root directory within GCS bucket name for $zone.
	 * @param string $zone Name of the zone, e.g. 'public' or 'thumb'.
	 * @return string Relative path, e.g. '' or '/thumb' (without trailing slash).
	 */
	private static function getRootForZone( $zone ) {
		return ( $zone === 'public' ) ? '' : "/$zone";
	}
}
