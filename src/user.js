import { createApp } from 'vue'
import MetaVoxPersonal from './components/MetaVoxPersonal.vue'
import { translate, translatePlural } from '@nextcloud/l10n'

const mountPoint = document.getElementById('metavox-personal')

let app = null

if (mountPoint) {
    app = createApp(MetaVoxPersonal)

    app.config.globalProperties.t = translate
    app.config.globalProperties.n = translatePlural
    app.config.globalProperties.OC = window.OC
    app.config.globalProperties.OCA = window.OCA

    app.mount(mountPoint)
}

export default app
