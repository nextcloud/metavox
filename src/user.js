import { createApp } from 'vue'
import MetaVoxPersonal from './components/MetaVoxPersonal.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { watchLoading } from './utils/updatedPage.js'

const mountPoint = document.getElementById('metavox-personal')

let app = null

if (mountPoint) {
    app = createApp(MetaVoxPersonal)

    app.config.globalProperties.t = translate
    app.config.globalProperties.n = translatePlural
    app.config.globalProperties.OC = window.OC
    app.config.globalProperties.OCA = window.OCA

    watchLoading(app).mount(mountPoint)
}

export default app
