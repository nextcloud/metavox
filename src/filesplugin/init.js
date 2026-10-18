/**
 * MetaVox init script. Nextcloud loads it before the Files app: NC31/32 read the
 * file actions only once, when their Files code loads.
 */

// eslint-disable-next-line camelcase
__webpack_public_path__ = document.querySelector('script[src*="metavox/js/metavox-init"]')?.src?.replace(/metavox-init\.js.*$/, '') || '/apps/metavox/js/'

import { registerBulkMetadataAction } from './BulkMetadataAction.js'
import { registerSearchView } from './searchView.js'

registerBulkMetadataAction()
// Before the Files app routes: an unknown view is sent to "All files".
registerSearchView()
