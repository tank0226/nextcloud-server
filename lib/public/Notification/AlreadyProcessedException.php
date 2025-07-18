<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCP\Notification;

use OCP\AppFramework\Attribute\Throwable;

#[Throwable(since: '17.0.0')]
class AlreadyProcessedException extends \RuntimeException {
	/**
	 * @since 17.0.0
	 */
	public function __construct() {
		parent::__construct('Notification is processed already');
	}
}
