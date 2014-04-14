<?php
/**
 * ownCloud
 *
 * @author Michael Gapczynski
 * @copyright 2013 Michael Gapczynski mtgap@owncloud.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OC\Files\Cache;

class Shared_Updater {

	// shares which can be removed from oc_share after the delete operation was successful
	static private $toRemove = array();

	/**
	 * @brief remove all shares for a given file if the file was deleted
	 *
	 * @param string $path
	 */
	private static function removeShare($path) {
		$fileSource = self::$toRemove[$path];

		if (!\OC\Files\Filesystem::file_exists($path)) {
			$query = \OC_DB::prepare('DELETE FROM `*PREFIX*share` WHERE `file_source`=?');
			try	{
				\OC_DB::executeAudited($query, array($fileSource));
			} catch (\Exception $e) {
				\OCP\Util::writeLog('files_sharing', "can't remove share: " . $e->getMessage(), \OCP\Util::WARN);
			}
		}
		unset(self::$toRemove[$path]);
	}

	/**
	 * @param array $params
	 */
	static public function deleteHook($params) {
		$fileInfo = \OC\Files\Filesystem::getFileInfo($params['path']);
		// mark file as deleted so that we can clean up the share table if
		// the file was deleted successfully
		self::$toRemove[$params['path']] =  $fileInfo['fileid'];
	}

	/**
	 * @param array $params
	 */
	static public function postDeleteHook($params) {
		self::removeShare($params['path']);
	}

	/**
	 * @param array $params
	 */
	static public function shareHook($params) {
		if ($params['itemType'] === 'file' || $params['itemType'] === 'folder') {
			if (isset($params['uidOwner'])) {
				$uidOwner = $params['uidOwner'];
			} else {
				$uidOwner = \OCP\User::getUser();
			}
			$users = \OCP\Share::getUsersItemShared($params['itemType'], $params['fileSource'], $uidOwner, true, false);
			if (!empty($users)) {
				while (!empty($users)) {
					$reshareUsers = array();
					foreach ($users as $user) {
						if ($user !== $uidOwner) {
							// Look for reshares
							$reshareUsers = array_merge($reshareUsers, \OCP\Share::getUsersItemShared('file', $params['fileSource'], $user, true));
						}
					}
					$users = $reshareUsers;
				}
			}
		}
	}

	/**
	 * clean up oc_share table from files which are no longer exists
	 *
	 * This fixes issues from updates from files_sharing < 0.3.5.6 (ownCloud 4.5)
	 * It will just be called during the update of the app
	 */
	static public function fixBrokenSharesOnAppUpdate() {
		// delete all shares where the original file no longer exists
		$findAndRemoveShares = \OC_DB::prepare('DELETE FROM `*PREFIX*share` ' .
			'WHERE `file_source` NOT IN ( ' .
				'SELECT `fileid` FROM `*PREFIX*filecache` WHERE `item_type` IN (\'file\', \'folder\'))'
		);
		$findAndRemoveShares->execute(array());
	}

}
