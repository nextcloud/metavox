<?php
$request = \OC::$server->getRequest();
$requestUri = $request->getRequestUri();

// Check URL patterns for Files app
$isFilesApp = (
    strpos($requestUri, '/apps/files') !== false ||
    strpos($requestUri, '/index.php/apps/files') !== false ||
    (isset($_GET['app']) && $_GET['app'] === 'files') ||
    (isset($_POST['app']) && $_POST['app'] === 'files')
);

// Check if in admin settings
$isAdminSettings = (
    strpos($requestUri, '/settings/admin') !== false ||
    strpos($requestUri, '/index.php/settings/admin') !== false
);

// Only load scripts when in Files app
if ($isFilesApp) {
    \OCP\Util::addScript('metavox', 'files-plugin1');
    \OCP\Util::addStyle('metavox', 'files');
}