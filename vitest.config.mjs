import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

const stub = (name) => fileURLToPath(new URL(`./tests/js/stubs/${name}.js`, import.meta.url))

export default defineConfig({
	test: {
		projects: [
			// Unit tests for the frontend's pure modules; no browser or Nextcloud needed.
			{
				test: {
					name: 'unit',
					include: ['tests/js/**/*.test.js'],
					environment: 'node',
				},
			},
			// Components mounted in happy-dom. The @nextcloud packages expect a
			// running Nextcloud and import CSS, so they are replaced by the stubs;
			// the deep imports (@nextcloud/vue/components/NcButton) all map onto one.
			{
				plugins: [vue()],
				test: {
					name: 'components',
					include: ['tests/js/**/*.spec.js'],
					environment: 'happy-dom',
					globals: true,
					setupFiles: [stub('storage')],
				},
				resolve: {
					alias: [
						{ find: /^@nextcloud\/vue\/components\/(.*)$/, replacement: stub('nextcloud-vue-component') },
						{ find: /^@nextcloud\/vue\/composables\/(.*)$/, replacement: stub('nextcloud-vue-composables') },
						{ find: '@nextcloud/vue', replacement: stub('nextcloud-vue') },
						{ find: '@nextcloud/l10n', replacement: stub('nextcloud-l10n') },
						{ find: '@nextcloud/router', replacement: stub('nextcloud-router') },
						{ find: '@nextcloud/axios', replacement: stub('nextcloud-axios') },
						{ find: '@nextcloud/dialogs', replacement: stub('nextcloud-dialogs') },
						{ find: '@nextcloud/initial-state', replacement: stub('nextcloud-initial-state') },
						{ find: '@', replacement: fileURLToPath(new URL('./src', import.meta.url)) },
					],
				},
			},
		],
	},
})
