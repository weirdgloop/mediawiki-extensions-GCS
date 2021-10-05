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
 * Iterator that removes N bytes from the beginning and end of strings returned by inner Iterator.
 * Used in getFileListInternal() and getDirectoryListInternal().
 */
class TrimStringIterator extends IteratorIterator {
	/** @var Iterator */
	private $innerIterator;

	/**
	 * Make an iterator that removes starting/trailing bytes of values from its internal iterator.
	 * @param Iterator $iterator Internal iterator over strings that need trimming.
	 * @param int $firstBytesToStrip How many starting bytes to remove.
	 * @param int $lastBytesToStrip How many trailing bytes to remove.
	 */
	public function __construct( Iterator $iterator) {
		parent::__construct( $iterator );
	}

	public function current() {
		return parent::current()->name();
	}
}
