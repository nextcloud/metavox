<template>
	<!-- A cell in miniature: the wash, the bar and the icon of the tone. Named
	     by its colour, so the colour is never only seen. -->
	<span class="mv-tone-sample"
		:data-mv-tone="tone"
		:role="decorative ? undefined : 'img'"
		:aria-label="decorative ? undefined : toneName(tone)"
		:aria-hidden="decorative ? 'true' : undefined">
		<svg viewBox="0 0 24 24"
			width="16"
			height="16"
			aria-hidden="true"
			focusable="false">
			<path fill="currentColor" :d="ICON_PATHS[tone] ?? ICON_PATHS.info" />
		</svg>
	</span>
</template>

<script setup>
import { toneName } from '../../utils/valueOperators.js'
import { ICON_PATHS } from './ToneIcons.js'

defineProps({
	tone: { type: String, required: true },
	// Beside its own colour word, a second name would be read twice.
	decorative: { type: Boolean, default: false },
})
</script>
