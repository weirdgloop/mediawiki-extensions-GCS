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
	 * Call installBackend() from $wgExtensionFunctions.
	 */
	public static function setup() {
		$hooks = new self;
		$hooks->installBackend();
	}

	/**
	 * Let MediaWiki know that GCSFileBackend is available.
	 *
	 * Note: we call this from $wgExtensionFunctions, not from SetupAfterCache hook,
	 * because replaceLocalRepo() needs User::isEveryoneAllowed(),
	 * which (for some reason) needs $wgContLang,
	 * and $wgContLang only gets defined after SetupAfterCache.
	 */
	public function installBackend() {
		global $wgFileBackends;

		if ( !isset( $wgFileBackends['gcs'] ) ) {
			$wgFileBackends['gcs'] = [];
		}
		$wgFileBackends['gcs']['name'] = 'GCS';
		$wgFileBackends['gcs']['class'] = 'GCSFileBackend';
		$wgFileBackends['gcs']['lockManager'] = 'nullLockManager';

		$this->replaceLocalRepo();
	}

	/**
	 * Replace $wgLocalRepo with GCS.
	 */
	protected function replaceLocalRepo() {
		global $wgFileBackends, $wgLocalFileRepo, $wgGCSTopSubdirectory, $wgScriptPath;

		/* Needed zones */
		$privateZones = [
			'deleted',
			'temp',
		];
		$publicZones = [
			'public',
			'thumb',
			'transcoded',
		];

		$wgLocalFileRepo = [
			'class'             => 'LocalRepo',
			'name'              => 'local',
			'backend'           => 'GCS',
			'url'               => $wgScriptPath . '/images',
			'hashLevels'        => 0,
			'deletedHashLevels' => 0,
			'zones'             => array_fill_keys( $zones, [ 'url' => false ] ),
			'transformVia404' => true
		];

		// Not a private wiki: $publicZones must have an URL
		foreach ( $publicZones as $zone ) {
			$wgLocalFileRepo['zones'][$zone] = [
				'url' => '/images' . $this->getRootForZone($zone)
			];
		}

		// Container names are prefixed by wfWikiID(), which depends on $wgDBPrefix and $wgDBname.
		$wikiId = wfWikiID();
		$containerPaths = [];
		$zones = [ ...$privateZones, ...$publicZones ];
		foreach ( $zones as $zone ) {
			$containerPaths["$wikiId-local-$zone"] = $wikiId . $this->getRootForZone($zone);
		}
		$wgFileBackends['gcs']['containerPaths'] = $containerPaths;
	}

	/**
	 * Returns root directory within GCS bucket name for $zone.
	 * @param string $zone Name of the zone, e.g. 'public' or 'thumb'.
	 * @return string Relative path, e.g. '' or '/thumb' (without trailing slash).
	 */
	protected function getRootForZone( $zone ) {
		return ( $zone === 'public' ) ? '' : "/$zone";
	}
}
