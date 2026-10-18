<?php

declare(strict_types=1);

// Stand-ins for Nextcloud's DAV app classes, which only exist inside a
// Nextcloud install. Signatures follow NC 35 (apps/dav); tests mock them.

namespace OCA\DAV\Connector\Sabre {

    if (!class_exists(Node::class)) {
        abstract class Node implements \Sabre\DAV\INode {
            abstract public function getPath(): string;

            abstract public function getId(): ?int;

            abstract public function getInternalFileId(): ?int;

            abstract public function getNode(): \OCP\Files\Node;
        }

        abstract class Directory extends Node implements \Sabre\DAV\ICollection {
            abstract public function getNode(): \OCP\Files\Folder;
        }

        abstract class File extends Node implements \Sabre\DAV\IFile {
            abstract public function getNode(): \OCP\Files\File;
        }
    }
}

namespace OCA\DAV\Events {

    if (!class_exists(SabrePluginAddEvent::class)) {
        class SabrePluginAddEvent extends \OCP\EventDispatcher\Event {
            public function __construct(
                private \Sabre\DAV\Server $server,
            ) {
                parent::__construct();
            }

            public function getServer(): \Sabre\DAV\Server {
                return $this->server;
            }
        }
    }
}
