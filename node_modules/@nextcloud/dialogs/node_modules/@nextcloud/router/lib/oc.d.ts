/// <reference types="@nextcloud/typings" />

declare interface Window {
    OC: Nextcloud.v26.OC | Nextcloud.v27.OC;

    // Private state directly from server
    _oc_webroot?: string
    _oc_appswebroots?: Record<string, string|undefined>
}
