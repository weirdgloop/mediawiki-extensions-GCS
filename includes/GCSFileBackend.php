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

if ( !class_exists( "\\Google\\Cloud\\Storage\\StorageClient" ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
}

use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Storage\StorageClient;
use Psr\Log\LogLevel;

/**
 * FileBackend for Google Cloud Storage
 *
 * @author Tyler Romeo <tylerromeo@gmail.com>
 * @author Thai Phan <thai@outlook.com>
 * @author Edward Chernenko <edwardspec@gmail.com>
 * @author Jonathan Lee <cookmeplox@weirdgloop.org>

 */
class GCSFileBackend extends FileBackendStore {
	/**
	 * GCS bucket to use
	 */
	private $bucket;

	/**
	 * @var array
	 * Maps names of containers (e.g. mywiki-local-thumb) to "/some/path"
	 * where "some/path" is the "top directory" prefix of GCS object names.
	 *
	 * @phan-var array<string,string>
	 */
	private $containerPaths;

	/**
	 * Maximum length of GCS object name.
	 * See https://cloud.google.com/storage/docs/naming-objects for details.
	 */
	const MAX_GCS_OBJECT_NAME_LENGTH = 1024;

	/**
	 * Construct the backend. Doesn't take any extra config parameters.
	 *
	 * The configuration array may contain the following keys in addition
	 * to the keys accepted by FileBackendStore::__construct:
	 *  * containerPaths (required) - Mapping of container names to paths
	 *
	 * @param array $config
	 */
	public function __construct( array $config ) {
		global $wgGCSBucket, $wgGCSCredentials;
		parent::__construct( $config );

		// Cache container information to mask latency
		if ( isset( $config['wanCache'] ) && $config['wanCache'] instanceof WANObjectCache ) {
			$this->memCache = $config['wanCache'];
		}

		$client = new StorageClient(['keyFilePath' => $wgGCSCredentials]);
		$this->bucket = $client->bucket($wgGCSBucket);

		$this->containerPaths = (array)$config['containerPaths'];
	}

	/**
	 * Returns true if subdirectories are imaginary. This is always the case for GCS.
	 * (path like "a/b.txt" means an object with "a/b.txt" as its name, there is no directory "a")
	 * @return true
	 */
	protected function directoriesAreVirtual() {
		return true;
	}

	/**
	 * Check if a GCS object can be created/modified at this storage path.
	 * @param string $storagePath
	 * @return bool
	 */
	public function isPathUsableInternal( $storagePath ) {
		return true;
	}

	/**
	 * Returns null for invalid storage paths (in this case - when GCS object name is too long).
	 *
	 * @param string $container Container name
	 * @param string $relStoragePath Name of GCS object.
	 * @return string|null Name of GCS object (if valid) or null.
	 */
	protected function resolveContainerPath( $container, $relStoragePath ) {
		if ( strlen( $relStoragePath ) <= self::MAX_GCS_OBJECT_NAME_LENGTH ) {
			return $relStoragePath;
		} else {
			return null;
		}
	}

	/**
	 * Determine prefix of $container.
	 * @param string $container Internal container name (e.g. mywiki-local-thumb).
	 * @return string: prefix.
	 */
	protected function findContainerPrefix( $container ) {
		if ( empty( $this->containerPaths[$container] ) ) {
			return null; // Not configured
		}

		// In latter case, "dir1/dir2/" will be prepended to $filename.
		$containerPath = $this->containerPaths[$container];
		$firstSlashPos = strpos( $containerPath, '/' );
		if ( $firstSlashPos === false ) {
			fopen($containerPath, 'w');
			return [ $containerPath, "" ];
		}

		$prefix = substr( $containerPath, $firstSlashPos + 1 );

		if ( $prefix && substr( $prefix, -1 ) !== '/' ) {
			$prefix .= '/'; # Add trailing slash, e.g. "thumb/".
		}
		return $prefix;
	}

	/**
	 * Calculates name of GCS object from storagePath.
	 * @param string $storagePath Internal storage URL (mwstore://something/).
	 * @return string object name
	 */
	protected function getGCSName( $storagePath ) {
		list( $container, $filename ) = $this->resolveStoragePathReal( $storagePath );
		$prefix = $this->findContainerPrefix( $container );
		return $prefix . $filename;
	}

	// phpcs:disable Generic.Files.LineLength.TooLong

	/**
	 * Create a new GCS object.
	 * @param array $params
	 * @return Status
	 */
	protected function doCreateInternal( array $params ) {
		// phpcs:enable Generic.Files.LineLength.TooLong

		$key = $this->getGCSName( $params['dst'] );

		if ( $key == null ) {
			return Status::newFatal( 'backend-fail-invalidpath', $params['dst'] );
		}

		$contentType = isset( $params['headers']['Content-Type'] ) ?
			$params['headers']['Content-Type'] : null;

		if ( is_resource( $params['content'] ) && isset( $params['src'] ) ) {
			// If we are here, it means that doCreateInternal() was called from doStoreInternal().
			$sha1 = sha1_file( $params['src'] );
			if ( !$contentType ) {
				// Guess the MIME type from filename.
				$contentType = $this->getContentType( $params['dst'], null, $params['src'] );
			}
		} else {
			$sha1 = sha1( $params['content'] );
			if ( !$contentType ) {
				// Guess the MIME type from contents.
				$contentType = $this->getContentType( $params['dst'], $params['content'], null );
			}
		}

		$sha1Hash = Wikimedia\base_convert( $sha1, 16, 36, 31, true, 'auto' );
		//TODO: add sha1Hash
		$ret =  $this->bucket->upload($params['content'], ['name' => $key]);
		wfDebugLog("gcs_upload", $key);
		return Status::newGood();
	}

	/**
	 * Same as doCreateInternal(), but the source is a local file, not variable in memory.
	 * @param array $params
	 * @return Status
	 */
	protected function doStoreInternal( array $params ) {
		// Supply the open file to doCreateInternal() and have it do the rest.
		$params['content'] = fopen( $params['src'], 'r' );
		return $this->doCreateInternal( $params );
	}

	// phpcs:disable Generic.Files.LineLength.TooLong

	/**
	 * Copy an existing GCS object into another GCS object.
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param array{src:string,dst:string,headers?:array<string,string>,ignoreMissingSource?:bool} $params
	 */
	protected function doCopyInternal( array $params ) {
		// phpcs:enable Generic.Files.LineLength.TooLong

		$status = Status::newGood();

		$srcKey = $this->getGCSName( $params['src'] );
		$dstKey = $this->getGCSName( $params['dst'] );

		if ( $srcKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
		}
		if ( $dstKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
		}

		if ( !$status->isOK() ) {
			return $status;
		}

		$object = $this->bucket->object($srcKey);
		global $wgGCSBucket;
		$object->copy($wgGCSBucket, ['name' => $dstKey]);
		wfDebugLog("gcs_copy", $dstKey);
		return Status::newGood();
	}

	/**
	 * Delete an existing GCS object.
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param array{src:string,ignoreMissingSource?:bool} $params
	 */
	protected function doDeleteInternal( array $params ) {
		$status = Status::newGood();

		$key = $this->getGCSName( $params['src'] );
		if ( $key == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
			return $status;
		}

		$res = $this->bucket->object($key)->delete();
		wfDebugLog("gcs_delete", $key);
		return Status::newGood();
	}

	/**
	 * Check if "directory" $dir exists within $container.
	 * Note: in GCS, "directories" are imaginary, so existence means that there are GCS objects
	 * that have "$dir/" as the beginning of their name.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return bool
	 */
	protected function doDirectoryExists( $container, $dir, array $params ) {
		return true;
	}

	/**
	 * Obtain metadata (e.g. size, SHA1, etc.) of existing GCS object.
	 * @param array $params
	 * @return array|false|null
	 *
	 * @phan-param array{src:string} $params
	 * @phan-return array{mtime:string,size:int,etag:string,sha1:string}|false|null
	 */
	protected function doGetFileStat( array $params ) {
		$key = $this->getGCSName( $params['src'] );

		if ( $key == null ) {
			return null;
		}

		// 1) we don't need NotFound errors logged (these are not errors, because doGetFileStat
		// is meant to be used for "does this file exist" checks),
		// 2) if the bucket doesn't exist, there is no point in repeating this operation
		// after creating it, because the result will still be "file not found".
		try {
			$res = $this->bucket->object($key)->info();
			wfDebugLog("gcs_info", $key);
		} catch ( GoogleException $e ) {
			return false;
		}

		$sha1 = '';
		if ( isset( $res['Metadata']['sha1base36'] ) ) {
			$sha1 = $res['Metadata']['sha1base36'];
		}

		return [
			'mtime' => wfTimestamp( TS_MW, $res['updated'] ),
			'size' => (int)$res['size'],
			'etag' => $res['etag'],
			'sha1' => $sha1
		];
	}

	/**
	 * Obtain presigned URL of GCS object (from this URL it can be downloaded by HTTP(s) by anyone).
	 * @param array $params
	 * @return string|null
	 *
	 * @phan-param array{src:string} $params
	 */
	public function getFileHttpUrl( array $params ) {

		$key = $this->getGCSName( $params['src'] );
		try {
			// TODO: don't hardcode
			wfDebugLog("signed_url", $key);
			return $this->bucket->object($key)->signedUrl(1700000000);
		} catch ( GoogleException $e ) {
			return null;
		}
	}

	/**
	 * Obtain Iterator that lists "subdirectories" in $container under directory $dir.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Iterator
	 *
	 * @phan-param array{topOnly?:bool} $params
	 */
	public function getDirectoryListInternal( $container, $dir, array $params ) {
		$topOnly = !empty( $params['topOnly'] );
		$prefix = $this->findContainerPrefix( $container );
		$bucketDir = $prefix . $dir; // Relative to GCS bucket $bucket, not $container
		wfDebugLog("gcs_listdir", $dir);
		// TODO: this doesn't work
		return $this->bucket->objects(['prefix' => $bucketDir]);
	}

	/**
	 * Obtain Iterator that lists GCS objects in $container under subdirectory $dir.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Iterator
	 *
	 * @phan-param array{topOnly?:bool} $params
	 */
	public function getFileListInternal( $container, $dir, array $params ) {
		$topOnly = !empty( $params['topOnly'] );
		$prefix = $this->findContainerPrefix( $container );
		$dir = $prefix . $dir;
		wfDebugLog("gcs_listfiles", $dir);
		return GCSNameIterator($this->bucket->objects(['prefix' => $dir]));
	}

	/**
	 * Download GCS object $src.
	 * @param string $src
	 * @return FSFile|null Local temporary file that contains downloaded contents.
	 */
	protected function getLocalCopyCached( $src ) {
		$ext = FSFile::extensionFromPath( $virtualPath );
		$file = TempFSFile::factory( 'localcopy_', $ext );
		$dstPath = $file->getPath();

		if ( $file->exists() && $file->getSize() > 0 ) { // Found in cache
			return $file;
		}

		// Not found in the cache. Download from GCS.
		$srcPath = $this->getFileHttpUrl( [ 'src' => $src ] );
		if ( !$srcPath ) {
			return null; // Not found: no such object in GCS
		}

		wfMkdirParents( dirname( $dstPath ) );

		$ok = copy( $srcPath, $dstPath );

		if ( !$ok ) {
			return null; // Couldn't download the file from GCS (e.g. network issue)
		}
		return $file;
	}

	protected function doGetLocalCopyMulti( array $params ) {
		$fsFiles = [];
		$params += [
			'srcs' => $params['src'],
			'concurrency' => isset( $params['srcs'] ) ? count( $params['srcs'] ) : 1
		];
		foreach ( array_chunk( $params['srcs'], $params['concurrency'] ) as $pathBatch ) {
			foreach ( $pathBatch as $src ) {
				// TODO: remove this duplicate check, getFileHttpUrl() already checks this.
				$key = $this->getGCSName( $src );
				if ( $key === null ) {
					$fsFiles[$src] = null;
					continue;
				}

				$fsFiles[$src] = $this->getLocalCopyCached( $src );
			}
		}
		return $fsFiles;
	}

	/**
	 * Ensure that $container is usable. Calls doPublishInternal() and doSecureInternal().
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param $params array{noAccess?:bool,noListing?:bool,access?:bool,listing?:bool}
	 */
	protected function doPrepareInternal( $container, $dir, array $params ) {
		return Status::newGood();
	}

	/**
	 * Does nothing. In other backends - deletes empty subdirectory $dir within the container.
	 * This operation is not applicable to GCS, because its "subdirectories" are imaginary.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 */
	protected function doCleanInternal( $container, $dir, array $params ) {
		return Status::newGood(); /* Nothing to do */
	}

	/**
	 * Mark this container as published if $params['access'] is set.
	 * Being "published" means that new GCS objects here can be downloaded from GCS by anyone.
	 * @note ACL of existing GCS objects is not changed (impractical, not needed for 99,9% wikis).
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param $params array{access?:bool}
	 */
	protected function doPublishInternal( $container, $dir, array $params ) {
		return Status::newGood();
	}

	/**
	 * Mark this container as secure if $params['noAccess'] is set.
	 * Being "secure" means that new GCS objects here shouldn't be downloadable by general public.
	 * @note ACL of existing GCS objects is not changed (impractical, not needed for 99,9% wikis).
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param $params array{noAccess?:bool}
	 */
	protected function doSecureInternal( $container, $dir, array $params ) {
		return Status::newGood();
	}
}
