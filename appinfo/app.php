<?php
// In appinfo/app.php

// Alleen files-plugin1 globaal laden (voor Files app integratie)
\OCP\Util::addScript('metavox', 'files-plugin1');

// CSS voor files plugin
\OCP\Util::addStyle('metavox', 'files');
