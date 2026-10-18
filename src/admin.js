import { createApp } from 'vue'
import MetaVoxAdmin from './components/MetaVoxAdmin.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { watchLoading } from './utils/updatedPage.js'

const app = createApp(MetaVoxAdmin)

app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural
app.config.globalProperties.OC = window.OC
app.config.globalProperties.OCA = window.OCA

watchLoading(app).mount('#metavox-admin')
