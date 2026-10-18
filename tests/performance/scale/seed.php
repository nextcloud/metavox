<?php

declare(strict_types=1);

// The scale test's world, built straight into the database: 5,000 team folders
// (half with ACL rules), about 1.4 million files in their file cache, 8 fields
// with a value on every file, 10,000 users in about 15 team folders each, and
// three test users to log in with. Files exist only in the file cache: search
// needs nothing else. Run once in the app container, see README.md.
// env: SCALE_PASS, the test users' password.

require '/var/www/html/lib/base.php';

use OCA\GroupFolders\ACL\Rule;
use OCA\GroupFolders\ACL\RuleManager;
use OCA\GroupFolders\ACL\UserMapping\UserMapping;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

const FOLDERS = 5000;
const GROUPS = 1000;
const USERS = 10000;
// Files per team folder: three big ones, the rest 20 to 300.
const BIG = [1 => 500000, 2 => 100000, 3 => 10000];
const FILES_PER_DIR = 1000;

$db = \OCP\Server::get(IDBConnection::class);
$run = fn (string $sql, array $params = []) => $db->executeStatement($sql, $params);
$value = fn (string $sql, array $params = []) => $db->executeQuery($sql, $params)->fetchOne();
$say = function (string $message) {
    echo date('H:i:s ') . $message . "\n";
};

if ((int)$value('SELECT COUNT(*) FROM *PREFIX*group_folders') >= FOLDERS) {
    $say('already seeded');
    exit(0);
}
$pass = getenv('SCALE_PASS') ?: throw new RuntimeException('Set SCALE_PASS');

// Groups and users. Group g gets team folders g, g + 1000, ...: 5 each; a team
// folder has two groups, so a user in 2 groups is in up to 20 team folders.
$run("INSERT IGNORE INTO *PREFIX*groups (gid, displayname) SELECT CONCAT('scale-g', LPAD(seq, 4, '0')), CONCAT('Groep ', seq) FROM seq_1_to_" . GROUPS);
$run("INSERT IGNORE INTO *PREFIX*users (uid, displayname, password, uid_lower) SELECT CONCAT('scale-u', LPAD(seq, 5, '0')), CONCAT('Gebruiker ', seq), '!', CONCAT('scale-u', LPAD(seq, 5, '0')) FROM seq_1_to_" . USERS);
$run("INSERT IGNORE INTO *PREFIX*group_user (gid, uid) SELECT CONCAT('scale-g', LPAD(1 + MOD(u.seq * 7 + k.seq * 131, " . GROUPS . "), 4, '0')), CONCAT('scale-u', LPAD(u.seq, 5, '0')) FROM seq_1_to_" . USERS . ' u JOIN seq_1_to_2 k');
$say('groups and users');

// The test users, each in a group of their own.
$users = \OCP\Server::get(IUserManager::class);
$groups = \OCP\Server::get(IGroupManager::class);
foreach (['scale-big', 'scale-normal', 'scale-acl'] as $uid) {
    $user = $users->get($uid) ?? $users->createUser($uid, $pass);
    ($groups->get($uid . '-g') ?? $groups->createGroup($uid . '-g'))->addUser($user);
}

// Team folders through groupfolders itself: each its own storage and root.
$folders = \OCP\Server::get(FolderManager::class);
$ids = [];
for ($n = 1; $n <= FOLDERS; $n++) {
    $name = isset(BIG[$n]) ? 'Groot ' . BIG[$n] : sprintf('Map %04d', $n);
    $ids[$n] = $folders->createFolder($name);
    if ($n % 500 === 0) {
        $say("$n team folders");
    }
}
$run('CREATE TABLE IF NOT EXISTS scale_folders (n INT PRIMARY KEY, folder_id INT, storage INT, root_id BIGINT, files INT)');
$run('DELETE FROM scale_folders');
foreach (array_chunk($ids, 500, true) as $chunk) {
    $rows = [];
    foreach ($chunk as $n => $id) {
        $rows[] = sprintf('(%d, %d, %d)', $n, $id, BIG[$n] ?? 20 + ($n * 7919) % 281);
    }
    $run('INSERT INTO scale_folders (n, folder_id, files) VALUES ' . implode(',', $rows));
}
$run('UPDATE scale_folders s JOIN *PREFIX*group_folders g ON g.folder_id = s.folder_id JOIN *PREFIX*filecache r ON r.fileid = g.root_id SET s.storage = r.storage, s.root_id = r.fileid');

// Two groups per team folder; the test users' groups on chosen ones. Half
// the team folders use advanced permissions.
$run("INSERT INTO *PREFIX*group_folders_groups (folder_id, group_id, circle_id, permissions) SELECT folder_id, CONCAT('scale-g', LPAD(1 + MOD(n - 1, " . GROUPS . "), 4, '0')), '', 31 FROM scale_folders");
$run("INSERT IGNORE INTO *PREFIX*group_folders_groups (folder_id, group_id, circle_id, permissions) SELECT folder_id, CONCAT('scale-g', LPAD(1 + MOD((n - 1) * 37 + 500, " . GROUPS . "), 4, '0')), '', 31 FROM scale_folders");
$mine = [
    // The three big team folders and 12 small ones.
    'scale-big-g' => [1, 2, 3, ...range(101, 112)],
    'scale-normal-g' => range(201, 215),
    // The 100,000 one, where its ACL hides half the files.
    'scale-acl-g' => [2, ...range(301, 314)],
];
foreach ($mine as $gid => $numbers) {
    $run("INSERT IGNORE INTO *PREFIX*group_folders_groups (folder_id, group_id, circle_id, permissions) SELECT folder_id, ?, '', 31 FROM scale_folders WHERE n IN (" . implode(',', $numbers) . ')', [$gid]);
}
$run('UPDATE *PREFIX*group_folders SET acl = 1 WHERE MOD(folder_id, 2) = 0');
$say('team folders, groups and ACL switched on');

// Files: directories of 1,000 files under each team folder's root, mtimes
// spread over three years.
$mimes = \OCP\Server::get(IMimeTypeLoader::class);
$dir = $mimes->getId('httpd/unix-directory');
$httpd = $mimes->getId('httpd');
$text = $mimes->getId('text/plain');
$textPart = $mimes->getId('text');
$run("INSERT INTO *PREFIX*filecache (storage, path, path_hash, parent, name, mimetype, mimepart, size, mtime, storage_mtime, encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT s.storage, CONCAT('files/d', LPAD(d.seq, 4, '0')), MD5(CONCAT('files/d', LPAD(d.seq, 4, '0'))), s.root_id, CONCAT('d', LPAD(d.seq, 4, '0')),
        ?, ?, 0, UNIX_TIMESTAMP() - 3600, UNIX_TIMESTAMP() - 3600, 0, 0, MD5(RAND()), 31, ''
    FROM scale_folders s JOIN seq_1_to_500 d ON d.seq <= CEIL(s.files / " . FILES_PER_DIR . ')', [$dir, $httpd]);
$say('directories');
$run("INSERT INTO *PREFIX*filecache (storage, path, path_hash, parent, name, mimetype, mimepart, size, mtime, storage_mtime, encrypted, unencrypted_size, etag, permissions, checksum)
    SELECT d.storage, CONCAT(d.path, '/doc-', LPAD(f.seq, 4, '0'), '.txt'), MD5(CONCAT(d.path, '/doc-', LPAD(f.seq, 4, '0'), '.txt')), d.fileid,
        CONCAT('doc-', LPAD(f.seq, 4, '0'), '.txt'), ?, ?, 1000 + f.seq, UNIX_TIMESTAMP() - MOD(CRC32(CONCAT(d.fileid, '-', f.seq)), 94608000),
        UNIX_TIMESTAMP(), 0, 0, MD5(RAND()), 27, ''
    FROM *PREFIX*filecache d
    JOIN scale_folders s ON s.storage = d.storage AND d.parent = s.root_id
    JOIN seq_1_to_" . FILES_PER_DIR . ' f ON f.seq <= LEAST(' . FILES_PER_DIR . ', s.files - (CAST(SUBSTRING(d.name, 2) AS UNSIGNED) - 1) * ' . FILES_PER_DIR . ')', [$text, $textPart]);
$say('files: ' . $value('SELECT COUNT(*) FROM *PREFIX*filecache WHERE mimetype = ?', [$text]));

// ACL rules: in each ACL team folder its first directory is hidden from its
// second group; in the 100,000 one, half of it from scale-acl.
$rules = \OCP\Server::get(RuleManager::class);
$read = 1;
foreach ($db->executeQuery("SELECT s.n, s.storage, g.group_id FROM scale_folders s JOIN *PREFIX*group_folders f ON f.folder_id = s.folder_id AND f.acl = 1
        JOIN *PREFIX*group_folders_groups g ON g.folder_id = s.folder_id AND g.group_id LIKE 'scale-g%'")->fetchAll() as $i => $row) {
    if ($i % 2 === 1) {
        continue;
    }
    $first = (int)$value("SELECT fileid FROM *PREFIX*filecache WHERE storage = ? AND path = 'files/d0001'", [$row['storage']]);
    $rules->saveRule(new Rule(new UserMapping('group', $row['group_id']), $first, $read, 0));
}
$storage = (int)$value('SELECT storage FROM scale_folders WHERE n = 2');
foreach ($db->executeQuery("SELECT fileid FROM *PREFIX*filecache WHERE storage = ? AND parent = (SELECT root_id FROM scale_folders WHERE n = 2) AND name <= 'd0050'", [$storage])->fetchAll() as $row) {
    $rules->saveRule(new Rule(new UserMapping('group', 'scale-acl-g'), (int)$row['fileid'], $read, 0));
}
$say('ACL rules');

// Eight file fields of the usual types, on every team folder, with a value on
// every file: one value on 25% of them, rare ones, and a never used option.
$fields = [
    ['file_gf_status', 'Status', 'select', ['Concept', 'Review', 'Final', 'Gearchiveerd'],
        "CASE WHEN MOD(CRC32(c.fileid), 100) < 25 THEN 'Concept' WHEN MOD(CRC32(c.fileid), 100) < 45 THEN 'Review' WHEN MOD(CRC32(c.fileid), 100) < 95 THEN 'Final' ELSE 'Gearchiveerd' END"],
    ['file_gf_tags', 'Tags', 'multiselect', ['Beleid', 'Contract', 'Offerte', 'Rapport', 'Notulen', 'Factuur', 'Zeldzaam', 'Geheim'],
        "CONCAT_WS(';#', ELT(1 + MOD(CRC32(CONCAT('t', c.fileid)), 6), 'Beleid', 'Contract', 'Offerte', 'Rapport', 'Notulen', 'Factuur'),
            IF(MOD(CRC32(CONCAT('u', c.fileid)), 2) = 0, ELT(1 + MOD(CRC32(CONCAT('v', c.fileid)), 6), 'Notulen', 'Factuur', 'Beleid', 'Contract', 'Offerte', 'Rapport'), NULL),
            IF(MOD(CRC32(CONCAT('z', c.fileid)), 1000) = 0, 'Zeldzaam', NULL))"],
    ['file_gf_klant', 'Klant', 'text', [], "CONCAT('Klant ', MOD(c.fileid * 7, 5000))"],
    ['file_gf_projectcode', 'Projectcode', 'text', [], "CONCAT('PRJ-', c.fileid)"],
    ['file_gf_budget', 'Budget', 'number', [], 'MOD(c.fileid * 13, 100000)'],
    ['file_gf_deadline', 'Deadline', 'date', [], "DATE_FORMAT(DATE_ADD('2024-01-01', INTERVAL MOD(c.fileid, 1000) DAY), '%Y-%m-%d')"],
    ['file_gf_goedgekeurd', 'Goedgekeurd', 'checkbox', [], 'MOD(c.fileid, 2)'],
    ['file_gf_opmerkingen', 'Opmerkingen', 'textarea', [],
        "CONCAT('Notitie over dossier ', MOD(c.fileid, 20000), ' van ', ELT(1 + MOD(c.fileid, 5), 'HR', 'IT', 'Juridisch', 'Financien', 'Communicatie'))"],
];
foreach ($fields as $i => [$name, $label, $type, $options, $expression]) {
    $run('INSERT IGNORE INTO *PREFIX*metavox_gf_fields (field_name, field_label, field_type, field_options, is_required, sort_order, applies_to_groupfolder, created_at, updated_at)
        VALUES (?, ?, ?, ?, 0, ?, 0, NOW(), NOW())', [$name, $label, $type, json_encode($options), $i]);
    $run('INSERT IGNORE INTO *PREFIX*metavox_gf_assigns (groupfolder_id, field_id, created_at)
        SELECT s.folder_id, f.id, NOW() FROM scale_folders s JOIN *PREFIX*metavox_gf_fields f ON f.field_name = ?', [$name]);
    $run("INSERT INTO *PREFIX*metavox_file_gf_meta (file_id, groupfolder_id, field_name, field_value, created_at, updated_at)
        SELECT c.fileid, s.folder_id, ?, $expression, NOW(), NOW()
        FROM *PREFIX*filecache c JOIN scale_folders s ON s.storage = c.storage
        WHERE c.mimetype = ?", [$name, $text]);
    $say("values of $name");
}
$say('metadata rows: ' . $value('SELECT COUNT(*) FROM *PREFIX*metavox_file_gf_meta'));
