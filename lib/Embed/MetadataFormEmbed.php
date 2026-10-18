<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 VoxCloud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaVox\Embed;

use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Util;

/**
 * Lets another app show MetaVox's metadata panel, <metavox-metadata-form>.
 *
 * A host app calls this while rendering its page; MetaVox is optional to it:
 *
 *     if (class_exists(\OCA\MetaVox\Embed\MetadataFormEmbed::class)) {
 *         \OCP\Server::get(\OCA\MetaVox\Embed\MetadataFormEmbed::class)->load();
 *     }
 */
class MetadataFormEmbed {
	public const TAG_NAME = 'metavox-metadata-form';
	private const SCRIPT = 'metavox-embed';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * Installed but disabled for this user is not caught by class_exists().
	 */
	public function isAvailable(?string $userId = null): bool {
		$user = $userId === null ? null : $this->userManager->get($userId);
		return $this->appManager->isEnabledForUser('metavox', $user);
	}

	/**
	 * Queue the bundle that defines the element. Safe to call twice.
	 */
	public function load(): void {
		Util::addScript('metavox', self::SCRIPT);
	}
}
