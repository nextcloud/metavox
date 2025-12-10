import Vue from 'vue'
import MetaVoxAdmin from './components/MetaVoxAdmin.vue'
import { translate, translatePlural } from '@nextcloud/l10n'

Vue.prototype.t = translate
Vue.prototype.n = translatePlural
Vue.prototype.OC = window.OC
Vue.prototype.OCA = window.OCA

export default new Vue({
    el: '#metavox-admin',
    render: h => h(MetaVoxAdmin)
})