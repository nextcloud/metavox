<?php
/**
 * MetaVox Admin Template - Vue.js Version
 */

// Load Vue compiled JS
script('metavox', 'admin');

// Load base styles (minimal needed for Vue components)
style('metavox', 'admin');
?>

<div id="metavox-admin">
    <!-- Vue app will mount here -->
    <div class="loading-state" style="text-align: center; padding: 60px;">
        <div class="icon icon-loading-dark" style="display: inline-block;"></div>
        <p><?php p($l->t('Loading MetaVox...')); ?></p>
    </div>
</div>

<style>
/* Minimal CSS for loading state */
.loading-state {
    color: var(--color-text-maxcontrast);
}

.icon-loading-dark {
    width: 32px;
    height: 32px;
    background-image: url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAzMiAzMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTE2IDMwQzI0LjI4NDMgMzAgMzEgMjMuMjg0MyAzMSAxNUMzMSA2LjcxNTczIDI0LjI4NDMgMCAxNiAwIiBzdHJva2U9IiM3Njc2NzYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+Cjwvc3ZnPgo=');
    animation: rotate 1s linear infinite;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>