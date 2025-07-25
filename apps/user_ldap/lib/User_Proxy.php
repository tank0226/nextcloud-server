<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\User_LDAP;

use OCA\User_LDAP\User\DeletedUsersIndex;
use OCA\User_LDAP\User\OfflineUser;
use OCA\User_LDAP\User\User;
use OCP\IUserBackend;
use OCP\Notification\IManager as INotificationManager;
use OCP\User\Backend\ICountMappedUsersBackend;
use OCP\User\Backend\ILimitAwareCountUsersBackend;
use OCP\User\Backend\IProvideEnabledStateBackend;
use OCP\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * @template-extends Proxy<User_LDAP>
 */
class User_Proxy extends Proxy implements IUserBackend, UserInterface, IUserLDAP, ILimitAwareCountUsersBackend, ICountMappedUsersBackend, IProvideEnabledStateBackend {
	public function __construct(
		private Helper $helper,
		ILDAPWrapper $ldap,
		AccessFactory $accessFactory,
		private INotificationManager $notificationManager,
		private UserPluginManager $userPluginManager,
		private LoggerInterface $logger,
		private DeletedUsersIndex $deletedUsersIndex,
	) {
		parent::__construct($helper, $ldap, $accessFactory);
	}

	protected function newInstance(string $configPrefix): User_LDAP {
		return new User_LDAP(
			$this->getAccess($configPrefix),
			$this->notificationManager,
			$this->userPluginManager,
			$this->logger,
			$this->deletedUsersIndex,
		);
	}

	/**
	 * Tries the backends one after the other until a positive result is returned from the specified method
	 *
	 * @param string $id the uid connected to the request
	 * @param string $method the method of the user backend that shall be called
	 * @param array $parameters an array of parameters to be passed
	 * @return mixed the result of the method or false
	 */
	protected function walkBackends($id, $method, $parameters) {
		$this->setup();

		$uid = $id;
		$cacheKey = $this->getUserCacheKey($uid);
		foreach ($this->backends as $configPrefix => $backend) {
			$instance = $backend;
			if (!method_exists($instance, $method)
				&& method_exists($this->getAccess($configPrefix), $method)) {
				$instance = $this->getAccess($configPrefix);
			}
			if ($result = call_user_func_array([$instance, $method], $parameters)) {
				if (!$this->isSingleBackend()) {
					$this->writeToCache($cacheKey, $configPrefix);
				}
				return $result;
			}
		}
		return false;
	}

	/**
	 * Asks the backend connected to the server that supposely takes care of the uid from the request.
	 *
	 * @param string $id the uid connected to the request
	 * @param string $method the method of the user backend that shall be called
	 * @param array $parameters an array of parameters to be passed
	 * @param mixed $passOnWhen the result matches this variable
	 * @return mixed the result of the method or false
	 */
	protected function callOnLastSeenOn($id, $method, $parameters, $passOnWhen) {
		$this->setup();

		$uid = $id;
		$cacheKey = $this->getUserCacheKey($uid);
		$prefix = $this->getFromCache($cacheKey);
		//in case the uid has been found in the past, try this stored connection first
		if (!is_null($prefix)) {
			if (isset($this->backends[$prefix])) {
				$instance = $this->backends[$prefix];
				if (!method_exists($instance, $method)
					&& method_exists($this->getAccess($prefix), $method)) {
					$instance = $this->getAccess($prefix);
				}
				$result = call_user_func_array([$instance, $method], $parameters);
				if ($result === $passOnWhen) {
					//not found here, reset cache to null if user vanished
					//because sometimes methods return false with a reason
					$userExists = call_user_func_array(
						[$this->backends[$prefix], 'userExistsOnLDAP'],
						[$uid]
					);
					if (!$userExists) {
						$this->writeToCache($cacheKey, null);
					}
				}
				return $result;
			}
		}
		return false;
	}

	protected function activeBackends(): int {
		$this->setup();
		return count($this->backends);
	}

	/**
	 * Check if backend implements actions
	 *
	 * @param int $actions bitwise-or'ed actions
	 * @return boolean
	 *
	 * Returns the supported actions as int to be
	 * compared with \OC\User\Backend::CREATE_USER etc.
	 */
	public function implementsActions($actions) {
		$this->setup();
		//it's the same across all our user backends obviously
		return $this->refBackend->implementsActions($actions);
	}

	/**
	 * Backend name to be shown in user management
	 *
	 * @return string the name of the backend to be shown
	 */
	public function getBackendName() {
		$this->setup();
		return $this->refBackend->getBackendName();
	}

	/**
	 * Get a list of all users
	 *
	 * @param string $search
	 * @param null|int $limit
	 * @param null|int $offset
	 * @return string[] an array of all uids
	 */
	public function getUsers($search = '', $limit = 10, $offset = 0) {
		$this->setup();

		//we do it just as the /OC_User implementation: do not play around with limit and offset but ask all backends
		$users = [];
		foreach ($this->backends as $backend) {
			$backendUsers = $backend->getUsers($search, $limit, $offset);
			if (is_array($backendUsers)) {
				$users = array_merge($users, $backendUsers);
			}
		}
		return $users;
	}

	/**
	 * check if a user exists
	 *
	 * @param string $uid the username
	 * @return boolean
	 */
	public function userExists($uid) {
		$existsOnLDAP = false;
		$existsLocally = $this->handleRequest($uid, 'userExists', [$uid]);
		if ($existsLocally) {
			$existsOnLDAP = $this->userExistsOnLDAP($uid);
		}
		if ($existsLocally && !$existsOnLDAP) {
			try {
				$user = $this->getLDAPAccess($uid)->userManager->get($uid);
				if ($user instanceof User) {
					$user->markUser();
				}
			} catch (\Exception $e) {
				// ignore
			}
		}
		return $existsLocally;
	}

	/**
	 * check if a user exists on LDAP
	 *
	 * @param string|User $user either the Nextcloud user
	 *                          name or an instance of that user
	 */
	public function userExistsOnLDAP($user, bool $ignoreCache = false): bool {
		$id = ($user instanceof User) ? $user->getUsername() : $user;
		return $this->handleRequest($id, 'userExistsOnLDAP', [$user, $ignoreCache]);
	}

	/**
	 * Check if the password is correct
	 *
	 * @param string $uid The username
	 * @param string $password The password
	 * @return bool
	 *
	 * Check if the password is correct without logging in the user
	 */
	public function checkPassword($uid, $password) {
		return $this->handleRequest($uid, 'checkPassword', [$uid, $password]);
	}

	/**
	 * returns the username for the given login name, if available
	 *
	 * @param string $loginName
	 * @return string|false
	 */
	public function loginName2UserName($loginName) {
		$id = 'LOGINNAME,' . $loginName;
		return $this->handleRequest($id, 'loginName2UserName', [$loginName]);
	}

	/**
	 * returns the username for the given LDAP DN, if available
	 *
	 * @param string $dn
	 * @return string|false with the username
	 */
	public function dn2UserName($dn) {
		$id = 'DN,' . $dn;
		return $this->handleRequest($id, 'dn2UserName', [$dn]);
	}

	/**
	 * get the user's home directory
	 *
	 * @param string $uid the username
	 * @return boolean
	 */
	public function getHome($uid) {
		return $this->handleRequest($uid, 'getHome', [$uid]);
	}

	/**
	 * get display name of the user
	 *
	 * @param string $uid user ID of the user
	 * @return string display name
	 */
	public function getDisplayName($uid) {
		return $this->handleRequest($uid, 'getDisplayName', [$uid]);
	}

	/**
	 * set display name of the user
	 *
	 * @param string $uid user ID of the user
	 * @param string $displayName new display name
	 * @return string display name
	 */
	public function setDisplayName($uid, $displayName) {
		return $this->handleRequest($uid, 'setDisplayName', [$uid, $displayName]);
	}

	/**
	 * checks whether the user is allowed to change their avatar in Nextcloud
	 *
	 * @param string $uid the Nextcloud user name
	 * @return boolean either the user can or cannot
	 */
	public function canChangeAvatar($uid) {
		return $this->handleRequest($uid, 'canChangeAvatar', [$uid], true);
	}

	/**
	 * Get a list of all display names and user ids.
	 *
	 * @param string $search
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return array an array of all displayNames (value) and the corresponding uids (key)
	 */
	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		$this->setup();

		//we do it just as the /OC_User implementation: do not play around with limit and offset but ask all backends
		$users = [];
		foreach ($this->backends as $backend) {
			$backendUsers = $backend->getDisplayNames($search, $limit, $offset);
			if (is_array($backendUsers)) {
				$users = $users + $backendUsers;
			}
		}
		return $users;
	}

	/**
	 * delete a user
	 *
	 * @param string $uid The username of the user to delete
	 * @return bool
	 *
	 * Deletes a user
	 */
	public function deleteUser($uid) {
		return $this->handleRequest($uid, 'deleteUser', [$uid]);
	}

	/**
	 * Set password
	 *
	 * @param string $uid The username
	 * @param string $password The new password
	 * @return bool
	 *
	 */
	public function setPassword($uid, $password) {
		return $this->handleRequest($uid, 'setPassword', [$uid, $password]);
	}

	/**
	 * @return bool
	 */
	public function hasUserListings() {
		$this->setup();
		return $this->refBackend->hasUserListings();
	}

	/**
	 * Count the number of users
	 */
	public function countUsers(int $limit = 0): int|false {
		$this->setup();

		$users = false;
		foreach ($this->backends as $backend) {
			$backendUsers = $backend->countUsers($limit);
			if ($backendUsers !== false) {
				$users = (int)$users + $backendUsers;
				if ($limit > 0) {
					if ($users >= $limit) {
						break;
					}
					$limit -= $users;
				}
			}
		}
		return $users;
	}

	/**
	 * Count the number of mapped users
	 */
	public function countMappedUsers(): int {
		$this->setup();

		$users = 0;
		foreach ($this->backends as $backend) {
			$users += $backend->countMappedUsers();
		}
		return $users;
	}

	/**
	 * Return access for LDAP interaction.
	 *
	 * @param string $uid
	 * @return Access instance of Access for LDAP interaction
	 */
	public function getLDAPAccess($uid) {
		return $this->handleRequest($uid, 'getLDAPAccess', [$uid]);
	}

	/**
	 * Return a new LDAP connection for the specified user.
	 * The connection needs to be closed manually.
	 *
	 * @param string $uid
	 * @return \LDAP\Connection The LDAP connection
	 */
	public function getNewLDAPConnection($uid) {
		return $this->handleRequest($uid, 'getNewLDAPConnection', [$uid]);
	}

	/**
	 * Creates a new user in LDAP
	 *
	 * @param $username
	 * @param $password
	 * @return bool
	 */
	public function createUser($username, $password) {
		return $this->handleRequest($username, 'createUser', [$username, $password]);
	}

	public function isUserEnabled(string $uid, callable $queryDatabaseValue): bool {
		return $this->handleRequest($uid, 'isUserEnabled', [$uid, $queryDatabaseValue]);
	}

	public function setUserEnabled(string $uid, bool $enabled, callable $queryDatabaseValue, callable $setDatabaseValue): bool {
		return $this->handleRequest($uid, 'setUserEnabled', [$uid, $enabled, $queryDatabaseValue, $setDatabaseValue]);
	}

	public function getDisabledUserList(?int $limit = null, int $offset = 0, string $search = ''): array {
		if ((int)$this->getAccess(array_key_first($this->backends) ?? '')->connection->markRemnantsAsDisabled !== 1) {
			return [];
		}
		$disabledUsers = $this->deletedUsersIndex->getUsers();
		if ($search !== '') {
			$disabledUsers = array_filter(
				$disabledUsers,
				fn (OfflineUser $user): bool
					=> mb_stripos($user->getOCName(), $search) !== false
					|| mb_stripos($user->getUID(), $search) !== false
					|| mb_stripos($user->getDisplayName(), $search) !== false
					|| mb_stripos($user->getEmail(), $search) !== false,
			);
		}
		return array_map(
			fn (OfflineUser $user) => $user->getOCName(),
			array_slice(
				$disabledUsers,
				$offset,
				$limit
			)
		);
	}
}
