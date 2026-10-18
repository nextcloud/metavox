<?php

declare(strict_types=1);

namespace OCA\MetaVox\Access;

/**
 * Which Nextcloud check a route needs. Declared once per route with
 * #[RequireAccess]; the AccessMiddleware checks it before the controller runs.
 * Only MetaVoxManager is MetaVox's own right; the rest is Nextcloud's.
 */
enum Access: string {
    /** Nextcloud admins only; the route has no #[NoAdminRequired]. */
    case NcAdmin = 'nc_admin';

    /** Any logged-in user; the route returns only the caller's own or harmless data. */
    case LoggedIn = 'logged_in';

    /** Admins and members of the team folder (through a group or a circle). */
    case TeamFolderMember = 'team_folder_member';

    /**
     * Admins, and members with Nextcloud's update permission on the team
     * folder itself (ACL included): views and the team folder's own metadata.
     */
    case CanUpdateTeamFolder = 'can_update_team_folder';

    /** Admins, and team folder members with MetaVox's manage fields grant: assigning fields and default values. */
    case MetaVoxManager = 'metavox_manager';

    /** Nextcloud's read permission on the file in its team folder (ACL included). */
    case CanRead = 'can_read';

    /** Nextcloud's update permission on the file in its team folder (ACL included). */
    case CanUpdate = 'can_update';

    /** The caller supplies file ids: the controller checks every one with CanRead or CanUpdate. */
    case FileList = 'file_list';
}
