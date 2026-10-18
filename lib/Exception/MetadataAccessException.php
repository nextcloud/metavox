<?php

declare(strict_types=1);

namespace OCA\MetaVox\Exception;

use OCA\MetaVox\L10n\Translatable;
use OCP\AppFramework\Http;

/**
 * A refused metadata read or write. What the user may not see is a 404,
 * what they see but may not do a 403.
 */
class MetadataAccessException extends ClientErrorException {

    public static function notFound(): self {
        // TRANSLATORS Error message shown to the user when the file does not exist or cannot be seen
        return new self(Translatable::t('File not found'), Http::STATUS_NOT_FOUND, 'not_found');
    }

    public static function wrongGroupfolder(): self {
        // TRANSLATORS Error message shown to the user when a file belongs to another Team folder than the one asked for
        return new self(Translatable::t('File is not in this Team folder'), Http::STATUS_BAD_REQUEST, 'wrong_team_folder');
    }

    public static function readOnly(): self {
        // TRANSLATORS Error message shown to the user when the file is read-only for them
        return new self(Translatable::t('No permission to change the metadata of this file'), Http::STATUS_FORBIDDEN, 'read_only');
    }

    /** Not a member: answered as if the team folder isn't there. */
    public static function notMember(): self {
        // TRANSLATORS Error message shown to the user when a Team folder does not exist or they are not a member of it
        return new self(Translatable::t('Not found'), Http::STATUS_NOT_FOUND, 'not_found');
    }

    public static function notManager(): self {
        // TRANSLATORS Error message shown to the user; "Manage fields" is the name of a permission that lets someone change the metadata fields
        return new self(Translatable::t('Manage fields permission required'), Http::STATUS_FORBIDDEN, 'not_manager');
    }

    public static function teamFolderReadOnly(): self {
        // TRANSLATORS Error message shown to the user when the Team folder is read-only for them
        return new self(Translatable::t('No permission to change this Team folder'), Http::STATUS_FORBIDDEN, 'read_only');
    }

    public static function forbidden(): self {
        // TRANSLATORS Error message shown to the user when they may not do this action
        return new self(Translatable::t('Access denied'), Http::STATUS_FORBIDDEN, 'forbidden');
    }

    public static function badRequest(): self {
        // TRANSLATORS Error message shown to the user when a request has a wrong or missing ID number
        return new self(Translatable::t('Invalid ID'), Http::STATUS_BAD_REQUEST, 'invalid_id');
    }

}
