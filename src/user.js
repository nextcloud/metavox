import Vue from 'vue'
import MetaVoxPersonal from './components/MetaVoxPersonal.vue'
import { translate, translatePlural } from '@nextcloud/l10n'

Vue.prototype.t = translate
Vue.prototype.n = translatePlural
Vue.prototype.OC = window.OC
Vue.prototype.OCA = window.OCA

const mountPoint = document.getElementById('metavox-personal')

let app = null

if (mountPoint) {
    app = new Vue({
        el: mountPoint,
        render: h => h(MetaVoxPersonal)
    })
}

export default app