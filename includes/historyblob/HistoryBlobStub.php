<?php
/**
 * Efficient concatenated text storage.
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

use MediaWiki\MediaWikiServices;

/**
 * Pointer object for an item within a CGZ blob stored in the text table.
 */
class HistoryBlobStub {
	/**
	 * @var array One-step cache variable to hold base blobs; operations that
	 * pull multiple revisions may often pull multiple times from the same
	 * blob. By keeping the last-used one open, we avoid redundant
	 * unserialization and decompression overhead.
	 */
	protected static $blobCache = [];

	/** @var int */
	public $mOldId;

	/** @var string */
	public $mHash;

	/** @var string */
	public $mRef;

	/**
	 * @param string $hash The content hash of the text
	 * @param int $oldid The old_id for the CGZ object
	 */
	function __construct( $hash = '', $oldid = 0 ) {
		$this->mHash = $hash;
	}

	/**
	 * Sets the location (old_id) of the main object to which this object
	 * points
	 * @param int $id
	 */
	function setLocation( $id ) {
		$this->mOldId = $id;
	}

	/**
	 * Sets the location (old_id) of the referring object
	 * @param string $id
	 */
	function setReferrer( $id ) {
		$this->mRef = $id;
	}

	/**
	 * Gets the location of the referring object
	 * @return string
	 */
	function getReferrer() {
		return $this->mRef;
	}

	/**
	 * @return string|false
	 */
	function getText() {
		if ( isset( self::$blobCache[$this->mOldId] ) ) {
			$obj = self::$blobCache[$this->mOldId];
		} else {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'text',
				[ 'old_flags', 'old_text' ],
				[ 'old_id' => $this->mOldId ]
			);

			if ( !$row ) {
				return false;
			}

			$flags = explode( ',', $row->old_flags );
			if ( in_array( 'external', $flags ) ) {
				$url = $row->old_text;
				$parts = explode( '://', $url, 2 );
				if ( !isset( $parts[1] ) || $parts[1] == '' ) {
					return false;
				}
				$row->old_text = MediaWikiServices::getInstance()
					->getExternalStoreAccess()
					->fetchFromURL( $url );
			}

			if ( !in_array( 'object', $flags ) ) {
				return false;
			}

			if ( in_array( 'gzip', $flags ) ) {
				// This shouldn't happen, but a bug in the compress script
				// may at times gzip-compress a HistoryBlob object row.
				$obj = unserialize( gzinflate( $row->old_text ) );
			} else {
				$obj = unserialize( $row->old_text );
			}

			if ( !is_object( $obj ) ) {
				// Correct for old double-serialization bug.
				$obj = unserialize( $obj );
			}

			// Save this item for reference; if pulling many
			// items in a row we'll likely use it again.
			$obj->uncompress();
			self::$blobCache = [ $this->mOldId => $obj ];
		}

		return $obj->getItem( $this->mHash );
	}

	/**
	 * Get the content hash
	 *
	 * @return string
	 */
	function getHash() {
		return $this->mHash;
	}
}

// phpcs:ignore Generic.CodeAnalysis.UnconditionalIfStatement.Found
if ( false ) {
	// Blobs generated by MediaWiki < 1.5 on PHP 4 were serialized with the
	// class name coerced to lowercase. We can improve efficiency by adding
	// autoload entries for the lowercase variants of these classes (T166759).
	// The code below is never executed, but it is picked up by the AutoloadGenerator
	// parser, which scans for class_alias() calls.
	class_alias( HistoryBlobStub::class, 'historyblobstub' );
}