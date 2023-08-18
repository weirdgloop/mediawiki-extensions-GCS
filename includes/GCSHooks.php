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
		global $wgForeignFileRepos, $wgGCSForeignWikiDB, $wgGCSForeignWikiServer, $wgGCSPublic, $wgGCSThumbProxyUrl;

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

		/* Register local file repository and its backend. */
		$wikiId = WikiMap::getCurrentWikiId();
		$repoName = 'local';
		$repoBackendName = $repoName . '-gcs';
		$wgFileBackends[] = [
			'class' => GCSFileBackend::class,
			'name' => $repoBackendName,
			'domainId' => $wikiId,
			'lockManager' => 'nullLockManager',
			'containerPaths' => self::getContainerPaths( $zones, $repoName, $wikiId ),
		];

		$wgLocalFileRepo = [
			'class' => LocalRepo::class,
			'name' => $repoName,
			'backend' => $repoBackendName,
			'scriptDirUrl' => $wgScriptPath,
			'url' => $wgScriptPath . ( $wgGCSPublic ? '/images' : '/img_auth.php' ),
			'hashLevels' => 0,
			'thumbScriptUrl' => $wgThumbnailScriptPath,
			'transformVia404' => true,
			'deletedHashLevels' => 0,
			'isPrivate' => !$wgGCSPublic,
			'zones' => self::getZonesConf( $zones, $publicZones, ( $wgGCSPublic ? '/images' : '/img_auth.php' ) ),
		];

		// Use external thumbnailing.
		if ( $wgGCSThumbProxyUrl ) {
			// Disable local thumbnailing if external thumbnailing is being used.
			$wgLocalFileRepo['disableLocalTransform'] = true;
			$wgLocalFileRepo['thumbProxyUrl'] = $wgGCSThumbProxyUrl;
		}

		/* Register foreign file repository and its backend. */
		if ( $wgGCSForeignWikiDB && $wgGCSForeignWikiServer ) {
			$repoName = 'shared-' . $wgGCSForeignWikiDB;
			$repoBackendName = $repoName . '-gcs';
			$wgFileBackends[] = [
				'class' => GCSFileBackend::class,
				'name' => $repoBackendName,
				'domainId' => $wgGCSForeignWikiDB,
				'lockManager' => 'nullLockManager',
				'containerPaths' => self::getContainerPaths( $zones, $repoName, $wgGCSForeignWikiDB ),
			];

			$wgForeignFileRepos[] = [
				'class' => ForeignDBViaLBRepo::class,
				'name' => $repoName,
				'backend' => $repoBackendName,
				'scriptDirUrl' => $wgGCSForeignWikiServer . $wgScriptPath,
				'url' => $wgGCSForeignWikiServer . $wgScriptPath . '/images',
				'hashLevels' => 0,
				'thumbScriptUrl' => $wgThumbnailScriptPath ? ( $wgGCSForeignWikiServer . $wgThumbnailScriptPath ) : false,
				'transformVia404' => true,
				'deletedHashLevels' => 0,
				'zones' => self::getZonesConf( $zones, $publicZones, $wgGCSForeignWikiServer . '/images' ),
				// Foreign file repository specific properties.
				'descBaseUrl' => $wgGCSForeignWikiServer . '/w/File:',
				'fetchDescription' => true,
				'hasSharedCache' => true,
				'wiki' => $wgGCSForeignWikiDB,
			];
		}
	}

	private static function getContainerPaths( $zones, $repoName, $wikiId ) {
		$containerPaths = [];
		foreach ( $zones as $zone ) {
			$containerPaths["$wikiId-$repoName-$zone"] = $wikiId . self::getRootForZone($zone);
		}
		// GloopTweaks's "sitemaps" is unfortunately special.
		$containerPaths["$wikiId-sitemaps"] = $wikiId . self::getRootForZone('sitemaps');
		// EasyTimeline is unfortunately special.
		$containerPaths["$wikiId-timeline-render"] = $wikiId . self::getRootForZone('timeline');

		return $containerPaths;
	}

	private static function getZonesConf( $zones, $publicZones, $baseUrl ) {
		$zonesConf = array_fill_keys( $zones, [ 'url' => false ] );

		// Not a private wiki: $publicZones must have an URL
		foreach ( $publicZones as $zone ) {
			$zonesConf[$zone] = [
				'url' => $baseUrl . self::getRootForZone($zone)
			];
		}

		return $zonesConf;
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
