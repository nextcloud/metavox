/**
 * Stub for @nextcloud/dialogs. Toast helpers are no-ops that record nothing;
 * a test that cares can spy on them.
 */
export const showError = () => {}
export const showSuccess = () => {}
export const showWarning = () => {}
export const showInfo = () => {}
export const showMessage = () => {}
export const getFilePickerBuilder = () => ({
	setMultiSelect: () => getFilePickerBuilder(),
	setType: () => getFilePickerBuilder(),
	addMimeTypeFilter: () => getFilePickerBuilder(),
	allowDirectories: () => getFilePickerBuilder(),
	build: () => ({ pick: () => Promise.resolve('') }),
})
export const FilePickerType = { Choose: 1, Move: 2, Copy: 3, CopyMove: 4, Custom: 5 }
