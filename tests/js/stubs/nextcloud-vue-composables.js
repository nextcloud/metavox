/**
 * Stand-in for the deep imports of @nextcloud/vue/composables/*: the
 * composables the components use, at the values of a desktop browser.
 */

import { readonly, ref } from 'vue'

export const useIsMobile = () => readonly(ref(false))
export const useIsSmallMobile = () => readonly(ref(false))
